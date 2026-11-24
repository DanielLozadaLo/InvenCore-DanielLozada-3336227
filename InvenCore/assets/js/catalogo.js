/**
 * InvenCore - Catálogo y Registro de Productos (conectado al backend PHP)
 *
 * - READ:   al cargar, pide los productos a listar_productos.php (fetch) y dibuja la tabla
 * - CREATE: el modal envía por POST a registrar_producto.php
 * - UPDATE: el lápiz carga la fila en el mismo modal (modo edición) y envía a editar_producto.php
 * - DELETE: la papelera pide confirmación y llama a eliminar_producto.php (fetch)
 * - Búsqueda por SKU, nombre o categoría + filtro por categoría (sobre los datos cargados)
 */
document.addEventListener("DOMContentLoaded", () => {
  /* ---------- Rutas de los controladores y del login ---------- */
  const URL_LISTAR = "../../controllers/catalogo/listar_productos.php";
  const URL_REGISTRAR = "../../controllers/catalogo/registrar_producto.php";
  const URL_EDITAR = "../../controllers/catalogo/editar_producto.php";
  const URL_ELIMINAR = "../../controllers/catalogo/eliminar_producto.php";
  const URL_LOGIN = "../seguridad/login.html";

  /* ---------- Referencias al DOM ---------- */
  const searchInput = document.getElementById("buscarProducto");
  const categoriaLabel = document.getElementById("categoriaActual");
  const menuCategorias = document.getElementById("menuCategorias");
  const tbody = document.getElementById("productosBody");
  const filaEstado = document.getElementById("filaEstado");
  const celdaEstado = document.getElementById("celdaEstado");

  // Modal (sirve para registrar y para editar)
  const modalEl = document.getElementById("modalNuevoProducto");
  const form = document.getElementById("formNuevoProducto");
  const campo = {
    id: document.getElementById("prodId"),
    sku: document.getElementById("prodSku"),
    nombre: document.getElementById("prodNombre"),
    descripcion: document.getElementById("prodDescripcion"),
    precio: document.getElementById("prodPrecio"),
    stockActual: document.getElementById("prodStockActual"),
    stockMinimo: document.getElementById("prodStockMinimo"),
    ubicacion: document.getElementById("prodUbicacion"),
    categoria: document.getElementById("prodCategoria"),
  };
  const skuFeedback = document.getElementById("prodSkuFeedback");
  const modalTitulo = document.getElementById("modalProductoTitulo");
  const modalIcono = document.getElementById("modalProductoIcono");
  const btnGuardarTexto = document.getElementById("btnGuardarTexto");
  const avisoEdicion = document.getElementById("avisoEdicion");

  /* ---------- Estado ---------- */
  let productos = [];               // Datos cargados desde la base de datos
  let categoriaSeleccionada = "all";

  // Minúsculas y sin tildes ("Periféricos" == "perifericos")
  const normalize = (text) =>
    text.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");

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
    const body = document.createElement("div");
    body.className = "toast-body";
    body.textContent = message;                       // textContent: nunca se interpreta como HTML
    const close = document.createElement("button");
    close.type = "button";
    close.className = "btn-close btn-close-white me-2 m-auto";
    close.setAttribute("data-bs-dismiss", "toast");
    close.setAttribute("aria-label", "Cerrar");
    flex.append(body, close);
    toastEl.appendChild(flex);

    container.appendChild(toastEl);
    toastEl.addEventListener("hidden.bs.toast", () => toastEl.remove());
    new bootstrap.Toast(toastEl, { delay: 4000 }).show();
  }

  // Mensajes para los códigos de error que envían los controladores
  const ERRORES_SERVIDOR = {
    campos_vacios: "Completa todos los campos obligatorios.",
    sku_invalido: "El SKU solo puede tener letras, números y guiones (máximo 20 caracteres).",
    nombre_invalido: "El nombre no puede superar los 100 caracteres.",
    descripcion_larga: "La descripción no puede superar los 255 caracteres.",
    ubicacion_invalida: "Selecciona una ubicación SGA de la lista oficial.",
    precio_invalido: "El precio no es válido (usa números con hasta 2 decimales).",
    stock_invalido: "El stock debe ser un número entero de 0 o más.",
    categoria_invalida: "Selecciona una categoría válida.",
    sku_existente: "Ya existe un producto con ese SKU.",
    relacion_invalida: "La categoría seleccionada no existe.",
    id_invalido: "El producto indicado no es válido.",
    no_encontrado: "El producto ya no existe en el catálogo.",
    producto_con_movimientos: "No se puede eliminar: el producto tiene movimientos registrados.",
    error_servidor: "No pudimos completar la operación. Inténtalo de nuevo más tarde.",
  };

  /** Resultado de registrar/editar: los controladores redirigen con ?producto=... o ?error=... */
  function mostrarResultadoDelServidor() {
    const params = new URLSearchParams(window.location.search);
    const resultado = params.get("producto");
    const codigo = params.get("error");

    if (resultado === "ok") {
      showToast("Producto registrado en el catálogo", "success");
    } else if (resultado === "editado") {
      showToast("Producto actualizado correctamente", "success");
    } else if (codigo) {
      // Solo se muestran mensajes de la lista (nunca texto arbitrario de la URL)
      showToast(ERRORES_SERVIDOR[codigo] || ERRORES_SERVIDOR.error_servidor, "danger");
    }

    // Limpia la query de la barra de direcciones para que el aviso no reaparezca al recargar
    if (params.has("producto") || params.has("error")) {
      window.history.replaceState(null, "", window.location.pathname);
    }
  }

  /* =====================================================================
   * TABLA: construcción de filas
   * ===================================================================== */

  /** Crea la fila <tr> de un producto. Los datos se insertan con textContent (seguro contra XSS). */
  function crearFila(p) {
    const fila = document.createElement("tr");
    fila.dataset.id = String(p.id_producto);
    fila.dataset.sku = p.sku;
    fila.dataset.categoria = p.categoria;

    // Estructura fija: solo marcado propio, sin datos del usuario
    fila.innerHTML =
      '<td class="ps-4 cell-sku"></td>' +
      '<td><div class="prod-name"></div><div class="prod-cat"></div></td>' +
      '<td><div class="loc-main"><i class="bi bi-geo-alt"></i><span class="loc-text"></span></div></td>' +
      '<td class="prov"><i class="bi bi-building me-1"></i><span class="prov-text"></span></td>' +
      '<td class="text-end cell-price"></td>' +
      '<td class="text-center"><span class="stock-badge"></span><div class="stock-min"></div></td>' +
      '<td class="text-center pe-4 cell-actions">' +
      '<button type="button" class="btn-icon" data-action="editar" aria-label="Editar producto"><i class="bi bi-pencil"></i></button>' +
      '<button type="button" class="btn-icon" data-action="eliminar" aria-label="Eliminar producto"><i class="bi bi-trash"></i></button>' +
      "</td>";

    // Datos reales
    fila.querySelector(".cell-sku").textContent = p.sku;
    fila.querySelector(".prod-name").textContent = p.nombre;
    fila.querySelector(".prod-cat").textContent = p.categoria;
    fila.querySelector(".loc-text").textContent = p.ubicacion_sga || "Sin ubicación";
    fila.querySelector(".prov-text").textContent = p.proveedor;
    fila.querySelector(".cell-price").textContent = "$" + Number(p.precio).toFixed(2);

    // Badge de stock: crítico (rojo) si llegó al mínimo o está por debajo; suficiente (verde) en otro caso
    const critico = p.stock_actual <= p.stock_minimo;
    const badge = fila.querySelector(".stock-badge");
    badge.classList.add(critico ? "stock-low" : "stock-ok");
    badge.textContent = `${p.stock_actual} Unid.`;
    fila.querySelector(".stock-min").textContent = `Mín: ${p.stock_minimo}`;

    return fila;
  }

  /** Muestra la fila de estado: "cargando", "vacio", "sinResultados" o "error". */
  function mostrarEstado(tipo) {
    celdaEstado.replaceChildren();

    if (tipo === "cargando") {
      const spinner = document.createElement("span");
      spinner.className = "spinner-border spinner-border-sm me-2";
      spinner.setAttribute("aria-hidden", "true");
      celdaEstado.append(spinner, "Cargando productos...");
    } else if (tipo === "vacio") {
      celdaEstado.innerHTML = '<i class="bi bi-box-seam fs-4 d-block mb-2"></i>Aún no hay productos registrados.';
    } else if (tipo === "sinResultados") {
      celdaEstado.innerHTML = '<i class="bi bi-search fs-4 d-block mb-2"></i>No se encontraron productos.';
    } else if (tipo === "error") {
      celdaEstado.innerHTML =
        '<i class="bi bi-exclamation-triangle fs-4 d-block mb-2"></i>No se pudo cargar el catálogo.' +
        '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-action="reintentar">Reintentar</button></div>';
    }

    filaEstado.classList.remove("d-none");
  }

  /** Dibuja la tabla con los productos cargados y reaplica búsqueda y filtro. */
  function renderTabla() {
    tbody.querySelectorAll("tr[data-id]").forEach((fila) => fila.remove());
    productos.forEach((p) => tbody.insertBefore(crearFila(p), filaEstado));
    construirMenuCategorias();
    aplicarFiltros();
  }

  /* =====================================================================
   * BÚSQUEDA Y FILTRO POR CATEGORÍA
   * ===================================================================== */
  function aplicarFiltros() {
    const query = normalize(searchInput.value.trim());
    let visibles = 0;

    tbody.querySelectorAll("tr[data-id]").forEach((fila) => {
      const texto = normalize(
        [
          fila.querySelector(".cell-sku").textContent,
          fila.querySelector(".prod-name").textContent,
          fila.dataset.categoria,
        ].join(" ")
      );
      const coincideTexto = texto.includes(query);
      const coincideCategoria = categoriaSeleccionada === "all" || fila.dataset.categoria === categoriaSeleccionada;
      const mostrar = coincideTexto && coincideCategoria;

      fila.classList.toggle("d-none", !mostrar);
      if (mostrar) visibles++;
    });

    if (productos.length === 0) {
      mostrarEstado("vacio");
    } else if (visibles === 0) {
      mostrarEstado("sinResultados");
    } else {
      filaEstado.classList.add("d-none");
    }
  }

  /** Genera el desplegable con las categorías que existen en los productos cargados. */
  function construirMenuCategorias() {
    const categorias = [...new Set(productos.map((p) => p.categoria))].sort((a, b) => a.localeCompare(b, "es"));

    if (categoriaSeleccionada !== "all" && !categorias.includes(categoriaSeleccionada)) {
      categoriaSeleccionada = "all";
      categoriaLabel.textContent = "Todas las Categorías";
    }

    menuCategorias.replaceChildren();
    [["all", "Todas las Categorías"], ...categorias.map((c) => [c, c])].forEach(([valor, texto]) => {
      const li = document.createElement("li");
      const boton = document.createElement("button");
      boton.type = "button";
      boton.className = "dropdown-item" + (valor === categoriaSeleccionada ? " active" : "");
      boton.dataset.category = valor;
      boton.textContent = texto;
      li.appendChild(boton);
      menuCategorias.appendChild(li);
    });
  }

  searchInput.addEventListener("input", aplicarFiltros);

  menuCategorias.addEventListener("click", (event) => {
    const boton = event.target.closest("[data-category]");
    if (!boton) return;

    categoriaSeleccionada = boton.dataset.category;
    categoriaLabel.textContent = boton.textContent;
    menuCategorias.querySelectorAll("[data-category]").forEach((i) => i.classList.toggle("active", i === boton));
    aplicarFiltros();
  });

  /* =====================================================================
   * READ: cargar productos desde la base de datos
   * ===================================================================== */
  async function cargarProductos() {
    mostrarEstado("cargando");

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

      const datos = await response.json();
      if (!response.ok || !datos.ok) throw new Error(datos.error || "error_servidor");

      productos = datos.productos;
      renderTabla();
    } catch (error) {
      console.error("No se pudieron cargar los productos:", error);
      productos = [];
      renderTabla();                           // Limpia la tabla...
      mostrarEstado("error");                  // ...y muestra el estado de error
      showToast("No se pudo cargar el catálogo de productos.", "danger");
    }
  }

  /* =====================================================================
   * MODAL: modo registrar / modo editar
   * ===================================================================== */
  const enEdicion = () => form.dataset.modo === "editar";

  /** Garantiza que el <select> tenga una opción con ese valor (los ids reales vienen de la BD). */
  function asegurarOpcion(select, valor, texto) {
    const existe = [...select.options].some((o) => o.value === String(valor));
    if (!existe) select.add(new Option(texto, String(valor)));
  }

  /** Vuelve el modal a su estado normal: registrar un producto nuevo. */
  function restablecerModoRegistro() {
    form.dataset.modo = "registrar";
    form.setAttribute("action", URL_REGISTRAR);

    [campo.sku, campo.categoria].forEach((el) => (el.disabled = false));
    campo.id.disabled = true;                  // En modo registro no se envía id_producto

    form.reset();
    form.classList.remove("was-validated");
    campo.sku.setCustomValidity("");

    modalTitulo.textContent = "Registrar Nuevo Producto";
    modalIcono.className = "bi bi-plus-square text-success me-2";
    btnGuardarTexto.textContent = "Guardar Producto";
    avisoEdicion.classList.add("d-none");
  }

  /** UPDATE: carga los datos de un producto en el modal y lo abre en modo edición. */
  function abrirModalEdicion(p) {
    form.dataset.modo = "editar";
    form.setAttribute("action", URL_EDITAR);

    campo.id.disabled = false;                 // En modo edición sí se envía id_producto
    campo.id.value = String(p.id_producto);

    campo.sku.value = p.sku;
    campo.nombre.value = p.nombre;
    campo.descripcion.value = p.descripcion;
    campo.precio.value = Number(p.precio).toFixed(2);
    campo.stockActual.value = String(p.stock_actual);
    campo.stockMinimo.value = String(p.stock_minimo);
    // Ubicación: debe ser una zona oficial. Si el producto tiene una ubicación escrita a mano
    // (anterior a la lista oficial), el select queda sin elegir y hay que escoger una zona
    const ubicacionOficial = [...campo.ubicacion.options].some((o) => o.value === p.ubicacion_sga);
    campo.ubicacion.value = ubicacionOficial ? p.ubicacion_sga : "";
    if (!ubicacionOficial) {
      showToast("Este producto tiene una ubicación que no es oficial. Selecciona una zona del SGA.", "warning");
    }

    // Categoría: solo informativa (el UPDATE no la modifica)
    if (p.id_categoria !== null) {
      asegurarOpcion(campo.categoria, p.id_categoria, p.categoria);
      campo.categoria.value = String(p.id_categoria);
    }
    [campo.sku, campo.categoria].forEach((el) => (el.disabled = true));

    form.classList.remove("was-validated");
    campo.sku.setCustomValidity("");

    modalTitulo.textContent = "Editar Producto";
    modalIcono.className = "bi bi-pencil-square text-success me-2";
    btnGuardarTexto.textContent = "Guardar Cambios";
    avisoEdicion.classList.remove("d-none");

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  // Al cerrar el modal (de cualquier forma) vuelve al modo registro y se limpia el formulario
  modalEl.addEventListener("hidden.bs.modal", restablecerModoRegistro);

  /** El SKU debe ser único: se compara contra los productos cargados (solo al registrar). */
  function validarSku() {
    if (enEdicion()) {
      campo.sku.setCustomValidity("");
      return;
    }

    const sku = campo.sku.value.trim().toUpperCase();
    const duplicado = sku !== "" && productos.some((p) => p.sku.toUpperCase() === sku);

    campo.sku.setCustomValidity(duplicado ? "SKU duplicado" : "");
    skuFeedback.textContent = duplicado
      ? "Este SKU ya existe en el catálogo."
      : "Ingresa un SKU válido (letras, números y guiones).";
  }

  campo.sku.addEventListener("input", () => {
    campo.sku.value = campo.sku.value.toUpperCase();
    validarSku();
  });

  form.addEventListener("submit", (event) => {
    validarSku();

    if (!form.checkValidity()) {
      // Hay errores: se detiene el envío y se muestran los mensajes de Bootstrap
      event.preventDefault();
      event.stopPropagation();
      form.classList.add("was-validated");
      return;
    }

    // Datos válidos: NO se llama a preventDefault(). El navegador envía el formulario por POST
    // a registrar_producto.php o a editar_producto.php (según el atributo action del modo actual).
    // El servidor vuelve a validar todo: nunca se confía solo en el frontend.
  });

  /* =====================================================================
   * DELETE: eliminar un producto
   * ===================================================================== */
  async function eliminarProducto(producto) {
    if (!confirm(`¿Estás seguro de eliminar este producto?\n\n${producto.sku} · ${producto.nombre}`)) return;

    try {
      const response = await fetch(URL_ELIMINAR, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        body: new URLSearchParams({ id_producto: String(producto.id_producto) }),
      });

      if (response.status === 401) {
        window.location.replace(URL_LOGIN);
        return;
      }

      const datos = await response.json();
      if (!response.ok || !datos.ok) {
        showToast(ERRORES_SERVIDOR[datos.error] || ERRORES_SERVIDOR.error_servidor, "danger");
        if (datos.error === "no_encontrado") cargarProductos(); // Ya no existe: se actualiza la tabla
        return;
      }

      // Éxito: se quita del arreglo y se vuelve a dibujar la tabla
      productos = productos.filter((p) => p.id_producto !== producto.id_producto);
      renderTabla();
      showToast(`Producto ${producto.sku} eliminado`, "success");
    } catch (error) {
      console.error("No se pudo eliminar el producto:", error);
      showToast(ERRORES_SERVIDOR.error_servidor, "danger");
    }
  }

  /* =====================================================================
   * Acciones de la tabla (delegación de eventos: las filas se crean dinámicamente)
   * ===================================================================== */
  tbody.addEventListener("click", (event) => {
    const boton = event.target.closest("[data-action]");
    if (!boton) return;

    if (boton.dataset.action === "reintentar") {
      cargarProductos();
      return;
    }

    const fila = boton.closest("tr[data-id]");
    if (!fila) return;

    const producto = productos.find((p) => p.id_producto === Number(fila.dataset.id));
    if (!producto) return;

    if (boton.dataset.action === "editar") abrirModalEdicion(producto);
    if (boton.dataset.action === "eliminar") eliminarProducto(producto);
  });

  /* ---------- Inicio ---------- */
  mostrarResultadoDelServidor();
  cargarProductos();
});