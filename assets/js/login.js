/**
 * InvenCore - Login (frontend)
 * 1) Mostrar/ocultar contraseña
 * 2) Validación visual (HTML5 + Bootstrap) antes de enviar
 * 3) Mostrar los mensajes que devuelve el servidor (?error=codigo / ?registro=ok)
 *
 * Si los datos son válidos, el formulario se envía por POST a
 * controllers/seguridad/login.php: la verificación de credenciales y la
 * sesión las gestiona el servidor.
 */
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("loginForm");
  const passwordInput = document.getElementById("password");
  const toggleBtn = document.getElementById("togglePassword");
  const toggleIcon = document.getElementById("togglePasswordIcon");
  const errorAlert = document.getElementById("loginError");
  const infoAlert = document.getElementById("loginInfo");

  // Mensajes para los códigos de error que envía login.php
  const ERRORES_SERVIDOR = {
    campos_vacios: "Ingresa tu correo y tu contraseña.",
    credenciales: "Correo o contraseña incorrectos.",
    error_servidor: "No pudimos iniciar sesión. Inténtalo de nuevo más tarde.",
  };

  // ---------- Mensajes del servidor ----------
  function mostrarMensajesDelServidor() {
    const params = new URLSearchParams(window.location.search);
    const codigo = params.get("error");

    if (codigo) {
      // Solo se muestran mensajes de la lista (nunca texto arbitrario de la URL)
      errorAlert.textContent = ERRORES_SERVIDOR[codigo] || ERRORES_SERVIDOR.error_servidor;
      errorAlert.classList.remove("d-none");
    } else if (params.get("registro") === "ok") {
      infoAlert.textContent = "Cuenta creada correctamente. Inicia sesión para continuar.";
      infoAlert.classList.remove("d-none");
    } else if (params.get("recuperacion") === "ok") {
      infoAlert.textContent = "Contraseña actualizada correctamente. Inicia sesión con tu nueva contraseña.";
      infoAlert.classList.remove("d-none");
    }

    // Limpia la query de la barra de direcciones para que el aviso no reaparezca al recargar
    if (codigo || params.has("registro") || params.has("recuperacion")) {
      window.history.replaceState(null, "", window.location.pathname);
    }
  }
  mostrarMensajesDelServidor();

  // ---------- Mostrar / ocultar contraseña ----------
  toggleBtn.addEventListener("click", () => {
    const isHidden = passwordInput.type === "password";
    passwordInput.type = isHidden ? "text" : "password";

    toggleIcon.classList.toggle("bi-eye", !isHidden);
    toggleIcon.classList.toggle("bi-eye-slash", isHidden);

    toggleBtn.setAttribute("aria-pressed", String(isHidden));
    toggleBtn.setAttribute("aria-label", isHidden ? "Ocultar contraseña" : "Mostrar contraseña");
    passwordInput.focus();
  });

  // ---------- Validación antes de enviar ----------
  form.addEventListener("submit", (event) => {
    if (!form.checkValidity()) {
      // Hay errores: se detiene el envío y se muestran los mensajes de Bootstrap
      event.preventDefault();
      event.stopPropagation();
      form.classList.add("was-validated");
      form.querySelector(":invalid").focus();
      return;
    }

    // Datos válidos: NO se llama a preventDefault(). El navegador envía el
    // formulario por POST a login.php, que verifica las credenciales en el servidor.
  });
});