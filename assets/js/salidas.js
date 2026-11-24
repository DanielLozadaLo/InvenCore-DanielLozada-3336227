/**
 * InvenCore - Gestión de Salidas, Picking y Packing (conectado al backend PHP)
 *
 * - READ:   al cargar, pide las salidas (y los productos con su stock) a listar_salidas.php
 *           y dibuja la tabla y los indicadores
 * - CREATE: el modal envía el formulario con fetch() a registrar_salida.php, sin recargar la página.
 *           La orden nace 'En Picking' y el servidor resta la cantidad al stock del producto
 * - UPDATE: los botones de "Avanzar Flujo" llaman a actualizar_estado_salida.php con fetch():
 *           En Picking → Embalado → Despachado
 * - Búsqueda (guía, cliente o producto) + filtro por etapa, sobre los datos cargados
 */
document.addEventListener("DOMContentLoaded", () => {
  /* ---------- Rutas de los controladores y del login ---------- */
  const URL_LISTAR = "../../controllers/logistica/listar_salidas.php";
  const URL_REGISTRAR = "../../controllers/logistica/registrar_salida.php";
  const URL_ACTUALIZAR = "../../controllers/logistica/actualizar_estado_salida.php";
  const URL_LOGIN = "../seguridad/login.html";

  /* ---------- Referencias al DOM ---------- */
  const body = document.getElementById("salidasBody");
  const filaEstado = document.getElementById("filaEstado");
  const celdaEstado = document.getElementById("celdaEstado");
  const searchInput = document.getElementById("buscarSalida");
  const etapaLabel = document.getElementById("etapaActual");
  const filtroItems = document.querySelectorAll(".sal-dropdown [data-filtro]"); // Solo los ítems del desplegable

  // Modal
  const modalEl = document.getElementById("modalNuevaOrden");
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const form = document.getElementById("formNuevaOrden");
  const selectProducto = document.getElementById("salProducto");
  const inputCantidad = document.getElementById("salCantidad");
  const feedbackCantidad = document.getElementById("salCantidadFeedback");
  const infoStock = document.getElementById("salStockInfo");
  const errorModal = document.getElementById("salidaError");
  const btnGenerar = document.getElementById("btnGenerarOrden");
  const spinner = document.getElementById("salidaSpinner");
  const iconoBoton = document.getElementById("salidaIcono");

  /* ---------- Estado ---------- */
  let salidas = [];        // Movimientos de tipo SALIDA
  let productos = [];      // Catálogo para el select del modal (con su stock)
  let etapaFiltro = "all";

  const CANTIDAD_MAXIMA = 1000000;

  /* ---------- Utilidades ---------- */
  // Minúsculas y sin tildes ("Corporación" == "corporacion")
  const normalize = (text) =>
    text.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");

  const formatear = (n) => Number(n).toLocaleString("es-CO");

  /**
   * Etapas del despacho (el texto guardado en movimientos.estado, normalizado).
   * boton: la acción que avanza a la siguiente etapa (null si es la etapa final).
   */
  const ETAPAS = {
    pendiente: {
      clave: "pendiente", etiqueta: "Pendiente", clase: "etapa-pendiente", icono: "bi-clock",
      boton: { texto: "Iniciar Picking", clase: "btn-flujo-orange", accion: "iniciar_picking" },
    },
    "en picking": {
      clave: "picking", etiqueta: "En Picking", clase: "etapa-picking", icono: "bi-box",
      boton: { texto: "Completar Packing", clase: "btn-flujo-blue", accion: "completar_packing" },
    },
    embalado: {
      clave: "packing", etiqueta: "Embalado (Packed)", clase: "etapa-packing", icono: "bi-box-seam",
      boton: { texto: "Despachar a Ruta", clase: "btn-flujo-green", accion: "despachar" },
    },
    despachado: {
      clave: "despachado", etiqueta: "Despachado", clase: "etapa-despachado", icono: "bi-truck",
      boton: null,
    },
  };
  const etapaDe = (texto) =>
    ETAPAS[normalize(texto)] || { clave: "otro", etiqueta: texto || "Sin estado", clase: "etapa-pendiente", icono: "bi-question-circle", boton: null };

  // Mensajes para los códigos de error que envían los controladores
  const ERRORES_SERVIDOR = {
    campos_vacios: "Completa todos los campos obligatorios.",
    producto_invalido: "Selecciona un producto válido.",
    cantidad_invalida: "La cantidad debe ser un número entero entre 1 y 1.000.000.",
    motivo_largo: "El cliente o destino no puede superar los 150 caracteres.",
    producto_no_encontrado: "El producto seleccionado ya no existe.",
    id_invalido: "El despacho indicado no es válido.",
    accion_invalida: "La acción solicitada no es válida.",
    no_encontrado: "El despacho ya no existe.",
    estado_desactualizado: "Este despacho ya cambió de etapa. Se actualizó la tabla.",
    error_servidor: "No pudimos completar la operación. Inténtalo de nuevo más tarde.",
  };

  /** Texto del error según la respuesta del servidor. */
  function mensajeError(datos) {
    if (datos && datos.error === "stock_insuficiente") {
      return `Stock insuficiente: solo hay ${formatear(datos.disponible)} unidades disponibles.`;
    }
    return ERRORES_SERVIDOR[datos && datos.error] || ERRORES_SERVIDOR.error_servidor;
  }

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

  /** Lee una respuesta del servidor: devuelve { datos } (datos = null si no era JSON). */
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
    return { datos };
  }

  /* =====================================================================
   * TABLA
   * ===================================================================== */

  /** Botón de avance de la columna "Avanzar Flujo", o el texto final "En Ruta". */
  function crearAccion(etapa) {
    if (etapa.boton) {
      const boton = document.createElement("button");
      boton.type = "button";
      boton.className = `btn btn-flujo ${etapa.boton.clase}`;
      boton.dataset.accion = etapa.boton.accion;
      boton.textContent = etapa.boton.texto;
      return boton;
    }

    if (etapa.clave === "despachado") {
      const texto = document.createElement("span");
      texto.className = "accion-ok";
      texto.innerHTML = '<i class="bi bi-check-circle me-1"></i>';
      texto.append("En Ruta");
      return texto;
    }

    return document.createTextNode("—");
  }

  /** Crea la fila <tr> de una salida. Los datos se insertan con textContent (seguro contra XSS). */
  function crearFila(s) {
    const etapa = etapaDe(s.estado);
    const fila = document.createElement("tr");
    fila.dataset.id = String(s.id_movimiento);
    fila.dataset.guia = s.nro_guia;
    fila.dataset.etapa = etapa.clave;

    // Estructura fija: solo marcado propio, sin datos del usuario
    fila.innerHTML =
      '<td class="ps-4"><div class="guia-cell"><i class="bi bi-arrow-up-right guia-icon"></i>' +
      '<div><div class="guia-code"></div><div class="guia-date"></div></div></div></td>' +
      '<td class="cell-cliente"></td>' +
      '<td><div class="cell-producto"></div><span class="qty-pill"></span></td>' +
      '<td><div class="loc-main"><i class="bi bi-geo-alt"></i><span class="loc-text"></span></div></td>' +
      '<td class="cell-etapa"></td>' +
      '<td class="cell-operador"><i class="bi bi-person-check me-2"></i><span class="operador-texto"></span></td>' +
      '<td class="text-end pe-4 cell-accion"></td>';

    fila.querySelector(".guia-code").textContent = s.nro_guia || `MOV-${s.id_movimiento}`;
    fila.querySelector(".guia-date").textContent = s.fecha.slice(0, 10);
    fila.querySelector(".cell-cliente").textContent = s.cliente;
    fila.querySelector(".cell-producto").textContent = s.producto;
    fila.querySelector(".cell-producto").title = s.sku;
    fila.querySelector(".qty-pill").textContent = `${formatear(s.cantidad)} ${s.cantidad === 1 ? "Unidad" : "Unidades"}`;
    fila.querySelector(".loc-text").textContent = s.ubicacion_sga || "Sin ubicación";
    fila.querySelector(".operador-texto").textContent = s.operador || "Sin asignar";

    // Etapa
    const badge = document.createElement("span");
    badge.className = `etapa-badge ${etapa.clase}`;
    const icono = document.createElement("i");
    icono.className = `bi ${etapa.icono} me-1`;
    badge.append(icono, etapa.etiqueta);
    fila.querySelector(".cell-etapa").appendChild(badge);

    fila.querySelector(".cell-accion").appendChild(crearAccion(etapa));
    return fila;
  }

  /** Muestra la fila de estado: "cargando", "vacio", "sinResultados" o "error". */
  function mostrarEstado(tipo) {
    celdaEstado.replaceChildren();

    if (tipo === "cargando") {
      const spinnerEl = document.createElement("span");
      spinnerEl.className = "spinner-border spinner-border-sm me-2";
      spinnerEl.setAttribute("aria-hidden", "true");
      celdaEstado.append(spinnerEl, "Cargando despachos...");
    } else if (tipo === "vacio") {
      celdaEstado.innerHTML = '<i class="bi bi-truck fs-4 d-block mb-2"></i>Aún no hay órdenes de despacho.';
    } else if (tipo === "sinResultados") {
      celdaEstado.innerHTML = '<i class="bi bi-search fs-4 d-block mb-2"></i>No se encontraron salidas.';
    } else if (tipo === "error") {
      celdaEstado.innerHTML =
        '<i class="bi bi-exclamation-triangle fs-4 d-block mb-2"></i>No se pudieron cargar los despachos.' +
        '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-action="reintentar">Reintentar</button></div>';
    }

    filaEstado.classList.remove("d-none");
  }

  /** Dibuja la tabla con las salidas cargadas y reaplica búsqueda y filtro. */
  function renderTabla() {
    body.querySelectorAll("tr[data-id]").forEach((fila) => fila.remove());
    salidas.forEach((s) => body.insertBefore(crearFila(s), filaEstado));
    actualizarKpis();
    aplicarFiltros();
  }

  /** Indicadores de las 4 etapas, calculados con las salidas cargadas. */
  function actualizarKpis() {
    const contar = (clave) => salidas.filter((s) => etapaDe(s.estado).clave === clave).length;

    document.getElementById("kpiPendiente").textContent = `${formatear(contar("pendiente"))} Pedidos`;
    document.getElementById("kpiPicking").textContent = `${formatear(contar("picking"))} En Curso`;
    document.getElementById("kpiPacking").textContent = `${formatear(contar("packing"))} Listos`;
    document.getElementById("kpiDespachado").textContent = `${formatear(contar("despachado"))} Entregados`;
  }

  /* =====================================================================
   * BÚSQUEDA Y FILTRO POR ETAPA
   * ===================================================================== */
  function aplicarFiltros() {
    const query = normalize(searchInput.value.trim());
    let visibles = 0;

    body.querySelectorAll("tr[data-id]").forEach((fila) => {
      const texto = normalize(
        [
          fila.dataset.guia,
          fila.querySelector(".cell-cliente").textContent,
          fila.querySelector(".cell-producto").textContent,
        ].join(" ")
      );
      const coincideTexto = texto.includes(query);
      const coincideEtapa = etapaFiltro === "all" || fila.dataset.etapa === etapaFiltro;
      const mostrar = coincideTexto && coincideEtapa;

      fila.classList.toggle("d-none", !mostrar);
      if (mostrar) visibles++;
    });

    if (salidas.length === 0) {
      mostrarEstado("vacio");
    } else if (visibles === 0) {
      mostrarEstado("sinResultados");
    } else {
      filaEstado.classList.add("d-none");
    }
  }

  searchInput.addEventListener("input", aplicarFiltros);

  filtroItems.forEach((item) => {
    item.addEventListener("click", () => {
      etapaFiltro = item.dataset.filtro;
      etapaLabel.textContent = item.textContent;
      filtroItems.forEach((i) => i.classList.toggle("active", i === item));
      aplicarFiltros();
    });
  });

  // Clics dentro de la tabla (las filas se crean dinámicamente: se usa delegación de eventos)
  body.addEventListener("click", (event) => {
    // Botón "Reintentar" de la fila de error
    if (event.target.closest('[data-action="reintentar"]')) {
      cargarSalidas();
      return;
    }

    // Botones de "Avanzar Flujo"
    const boton = event.target.closest("[data-accion]");
    if (!boton || boton.disabled) return;

    const fila = boton.closest("tr[data-id]");
    const salida = fila && salidas.find((s) => s.id_movimiento === Number(fila.dataset.id));
    if (salida) cambiarEstado(salida, boton.dataset.accion, boton);
  });

  /* =====================================================================
   * READ: cargar salidas desde la base de datos
   * ===================================================================== */
  async function cargarSalidas() {
    if (salidas.length === 0) mostrarEstado("cargando");

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

      salidas = datos.salidas;
      productos = datos.productos;
      renderTabla();
    } catch (error) {
      console.error("No se pudieron cargar las salidas:", error);
      salidas = [];
      renderTabla();
      mostrarEstado("error");
      showToast("No se pudieron cargar los despachos.", "danger");
    }
  }

  /* =====================================================================
   * UPDATE: avanzar un despacho por el flujo logístico
   *   "iniciar_picking":   Pendiente  → En Picking
   *   "completar_packing": En Picking → Embalado
   *   "despachar":         Embalado   → Despachado
   * ===================================================================== */
  const MENSAJES_FLUJO = {
    iniciar_picking: { texto: (g) => `La guía ${g} pasó a Picking.`, tipo: "primary" },
    completar_packing: { texto: (g) => `La guía ${g} fue embalada y está lista para despacho.`, tipo: "primary" },
    despachar: { texto: (g) => `La guía ${g} fue despachada a ruta.`, tipo: "success" },
  };

  async function cambiarEstado(salida, accion, boton) {
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
        body: new URLSearchParams({ id_movimiento: String(salida.id_movimiento), accion }),
      });

      if (response.status === 401) {       // Sesión vencida: al login
        window.location.replace(URL_LOGIN);
        return;
      }

      const { datos } = await leerRespuesta(response);

      if (!response.ok || !datos || !datos.ok) {
        showToast(mensajeError(datos), "danger");

        if (datos && datos.error === "estado_desactualizado") {
          await cargarSalidas();           // La tabla estaba desactualizada: se refresca
        } else {
          restaurarBoton();
        }
        return;
      }

      // Éxito: mensaje y recarga inmediata de la tabla y los indicadores
      const mensaje = MENSAJES_FLUJO[accion];
      showToast(mensaje.texto(datos.nro_guia), mensaje.tipo);
      await cargarSalidas();
    } catch (error) {
      console.error("No se pudo actualizar el estado del despacho:", error);
      showToast("No se pudo conectar con el servidor. Inténtalo de nuevo.", "danger");
      restaurarBoton();
    }
  }

  /* =====================================================================
   * MODAL: select de productos y control de stock
   * ===================================================================== */

  /** Llena el <select> con los productos y su stock; los productos sin stock no se pueden elegir. */
  function poblarProductos() {
    selectProducto.replaceChildren(
      new Option(productos.length ? "Selecciona un producto" : "No hay productos registrados", "", true, true)
    );
    selectProducto.options[0].disabled = true;

    productos.forEach((p) => {
      const opcion = new Option(
        `${p.sku} · ${p.nombre} (${p.stock_actual > 0 ? `stock: ${formatear(p.stock_actual)}` : "sin stock"})`,
        String(p.id_producto)
      );
      opcion.dataset.stock = String(p.stock_actual);
      opcion.disabled = p.stock_actual <= 0;
      selectProducto.add(opcion);
    });
  }

  /** Stock del producto elegido (o null si aún no se eligió ninguno). */
  function stockSeleccionado() {
    const opcion = selectProducto.selectedOptions[0];
    return opcion && opcion.dataset.stock !== undefined ? Number(opcion.dataset.stock) : null;
  }

  /** Ajusta el máximo permitido y el mensaje de la cantidad según el stock del producto elegido. */
  function actualizarLimiteCantidad() {
    const stock = stockSeleccionado();

    if (stock === null) {
      inputCantidad.max = String(CANTIDAD_MAXIMA);
      infoStock.textContent = "";
    } else {
      inputCantidad.max = String(Math.min(stock, CANTIDAD_MAXIMA));
      infoStock.textContent = `Disponible: ${formatear(stock)} unidades`;
    }

    const excede = stock !== null && Number(inputCantidad.value) > stock;
    feedbackCantidad.textContent = excede
      ? `Stock insuficiente: solo hay ${formatear(stock)} unidades disponibles.`
      : "Ingresa una cantidad entera mayor a 0.";
  }

  selectProducto.addEventListener("change", actualizarLimiteCantidad);
  inputCantidad.addEventListener("input", actualizarLimiteCantidad);

  // Al abrir el modal se llena el select con los datos más recientes de la base de datos
  modalEl.addEventListener("show.bs.modal", () => {
    poblarProductos();
    actualizarLimiteCantidad();
  });

  // Al cerrar el modal se limpia el formulario y los mensajes
  modalEl.addEventListener("hidden.bs.modal", () => {
    form.reset();
    form.classList.remove("was-validated");
    errorModal.classList.add("d-none");
    inputCantidad.max = String(CANTIDAD_MAXIMA);
    infoStock.textContent = "";
    feedbackCantidad.textContent = "Ingresa una cantidad entera mayor a 0.";
  });

  /* =====================================================================
   * CREATE: enviar el formulario con fetch() (sin recargar la página)
   * ===================================================================== */
  function mostrarErrorModal(mensaje) {
    errorModal.textContent = mensaje;
    errorModal.classList.remove("d-none");
  }

  function setEnviando(enviando) {
    btnGenerar.disabled = enviando;
    spinner.classList.toggle("d-none", !enviando);
    iconoBoton.classList.toggle("d-none", enviando);
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault(); // Se envía con fetch(), no con la recarga normal del formulario
    errorModal.classList.add("d-none");
    actualizarLimiteCantidad();

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
        mostrarErrorModal(mensajeError(datos));
        if (datos && datos.error === "stock_insuficiente") await cargarSalidas(); // El stock cambió: se refresca
        return;
      }

      modal.hide();
      showToast("Orden de despacho generada", "success");
      await cargarSalidas();           // Recarga la tabla, los indicadores y el stock del select
    } catch (error) {
      console.error("No se pudo registrar la salida:", error);
      mostrarErrorModal("No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.");
    } finally {
      setEnviando(false);
    }
  });

  /* ---------- Inicio ---------- */
  cargarSalidas();
});