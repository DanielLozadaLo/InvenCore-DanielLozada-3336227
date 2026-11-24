/**
 * InvenCore - Carga dinámica de componentes (sidebar, header, footer)
 *
 * Funciona desde la raíz y desde páginas anidadas (p. ej. views/catalogo/).
 * Las páginas anidadas declaran <body data-base="../../"> para indicar cómo
 * volver a la raíz del proyecto.
 */

const BASE = document.body.dataset.base || "";

/** Antepone BASE a los enlaces relativos de un componente recién inyectado. */
function fixRelativeLinks(container) {
  if (!BASE) return;
  container.querySelectorAll("a[href]").forEach((link) => {
    const href = link.getAttribute("href");
    if (/^(https?:|mailto:|tel:|#|\.\.\/|\/)/.test(href)) return; // absolutos, anclas o ya corregidos
    link.setAttribute("href", BASE + href);
  });
}

async function loadComponent(selector, url) {
  const container = document.querySelector(selector);
  if (!container) return;

  try {
    const response = await fetch(BASE + url);
    if (!response.ok) throw new Error(`HTTP ${response.status} al cargar ${url}`);
    container.innerHTML = await response.text();
    fixRelativeLinks(container);
  } catch (error) {
    console.error("Error cargando componente:", error);
    container.innerHTML =
      '<div class="alert alert-warning m-3" role="alert">' +
      "No se pudo cargar <code>" + url + "</code>. Usa un servidor local " +
      "(p. ej. Live Server) en lugar de abrir el archivo con file://.</div>";
  }
}

/** Resalta el enlace del sidebar correspondiente a la página actual. */
function setActiveNavLink() {
  const currentPage = window.location.pathname.split("/").pop() || "index.html";
  document.querySelectorAll("#sidebarMenu .nav-link[data-page]").forEach((link) => {
    const isActive = link.dataset.page === currentPage;
    link.classList.toggle("active", isActive);
    if (isActive) link.setAttribute("aria-current", "page");
  });
}

/** Cambia el título/subtítulo del header si la página lo define (data-title / data-subtitle). */
function setHeaderTexts() {
  const placeholder = document.getElementById("header-placeholder");
  const title = document.getElementById("topbarTitle");
  const subtitle = document.getElementById("topbarSubtitle");
  if (placeholder?.dataset.title && title) title.textContent = placeholder.dataset.title;
  if (placeholder?.dataset.subtitle && subtitle) subtitle.textContent = placeholder.dataset.subtitle;
}

/* =====================================================================
 * PROTECCIÓN DE RUTAS
 * Pregunta al servidor si hay una sesión activa. Si no la hay (o si el
 * servidor no responde), envía al usuario al login.
 * ===================================================================== */
const URL_LOGIN = BASE + "views/seguridad/login.html";
const URL_SESION = BASE + "controllers/seguridad/sesion.php";

async function verificarSesion() {
  try {
    const response = await fetch(URL_SESION, { credentials: "same-origin", cache: "no-store" });
    if (!response.ok) throw new Error("Sin sesión activa");

    const datos = await response.json();
    if (!datos.autenticado) throw new Error("Sin sesión activa");
    return datos;
  } catch (error) {
    window.location.replace(URL_LOGIN); // replace: la página protegida no queda en el historial
    return null;
  }
}

/** Muestra en la barra superior el nombre y el rol del usuario con sesión. */
function mostrarUsuario(sesion) {
  const nombre = document.querySelector(".user-name");
  const rol = document.querySelector(".user-role");
  if (nombre && sesion.nombre) nombre.textContent = sesion.nombre;
  if (rol && sesion.rol) {
    rol.textContent = "Rol: " + sesion.rol.charAt(0).toUpperCase() + sesion.rol.slice(1);
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  // La sesión y los componentes se cargan en paralelo
  const [sesion] = await Promise.all([
    verificarSesion(),
    loadComponent("#sidebar-placeholder", "components/sidebar.html"),
    loadComponent("#header-placeholder", "components/header.html"),
    loadComponent("#footer-placeholder", "components/footer.html"),
  ]);

  if (!sesion) return; // Ya se redirigió al login: la página sigue oculta

  setActiveNavLink();
  setHeaderTexts();
  mostrarUsuario(sesion);
  document.documentElement.classList.remove("auth-pending"); // Sesión válida: se muestra la página
});

// Si el navegador restaura la página desde su caché (botón "Atrás" tras cerrar sesión), se verifica de nuevo
window.addEventListener("pageshow", (event) => {
  if (event.persisted) verificarSesion();
});

/**
 * Top bar: notificaciones.
 * Se usa delegación de eventos porque el header se inyecta dinámicamente con fetch().
 */

// Notificaciones: ocultar el punto cuando ya no quedan notificaciones sin leer
function refreshNotifDot() {
  const hayNoLeidas = document.querySelector(".notif-item.unread") !== null;
  document.getElementById("notifDot")?.classList.toggle("d-none", !hayNoLeidas);
}

document.addEventListener("click", (event) => {
  // "Marcar como leídas": limpia el estado de todas
  if (event.target.closest("#btnMarcarLeidas")) {
    document.querySelectorAll(".notif-item.unread").forEach((item) => item.classList.remove("unread"));
    refreshNotifDot();
    return;
  }

  // Clic en una notificación: se marca como leída
  const item = event.target.closest(".notif-item.unread");
  if (item) {
    item.classList.remove("unread");
    refreshNotifDot();
  }
});