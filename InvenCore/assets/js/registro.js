/**
 * InvenCore - Registro de usuario (frontend)
 * 1) Mostrar/ocultar contraseñas
 * 2) Indicador de requisitos de contraseña
 * 3) Validación en vivo de coincidencia de contraseñas
 * 4) Validación visual al enviar; si es válido, el formulario viaja por POST a
 *    controllers/seguridad/registro.php
 * 5) Muestra los errores que devuelve el servidor (?error=codigo)
 */
document.addEventListener("DOMContentLoaded", () => {
  const MIN_PASSWORD_LENGTH = 8;

  const form = document.getElementById("registroForm");
  const nombre = document.getElementById("nombre");
  const password = document.getElementById("password");
  const confirmPassword = document.getElementById("confirmPassword");
  const confirmMsg = document.getElementById("confirmInvalidMsg");
  const hint = document.getElementById("passwordHint");
  const hintIcon = document.getElementById("passwordHintIcon");
  const errorAlert = document.getElementById("registroError");

  // Mensajes para los códigos de error que envía registro.php
  const ERRORES_SERVIDOR = {
    campos_vacios: "Completa todos los campos obligatorios.",
    nombre_invalido: "El nombre debe tener entre 3 y 100 caracteres.",
    correo_invalido: "Ingresa un correo electrónico válido.",
    password_corta: "La contraseña debe tener al menos 8 caracteres.",
    password_no_coincide: "Las contraseñas no coinciden.",
    correo_existente: "Este correo ya está registrado. Intenta iniciar sesión.",
    error_servidor: "No pudimos completar el registro. Inténtalo de nuevo más tarde.",
  };

  // ---------- 5) Error devuelto por el servidor ----------
  function mostrarErrorDelServidor() {
    const codigo = new URLSearchParams(window.location.search).get("error");
    if (!codigo) return;

    // Solo se muestran mensajes de la lista (nunca texto arbitrario de la URL)
    errorAlert.textContent = ERRORES_SERVIDOR[codigo] || ERRORES_SERVIDOR.error_servidor;
    errorAlert.classList.remove("d-none");

    // Limpia el ?error= de la barra de direcciones para que no reaparezca al recargar
    window.history.replaceState(null, "", window.location.pathname);
  }
  mostrarErrorDelServidor();

  // ---------- 1) Mostrar / ocultar contraseña (ambos campos) ----------
  document.querySelectorAll(".toggle-password").forEach((btn) => {
    btn.addEventListener("click", () => {
      const input = document.getElementById(btn.dataset.target);
      const icon = btn.querySelector("i");
      const show = input.type === "password";

      input.type = show ? "text" : "password";
      icon.classList.toggle("bi-eye", !show);
      icon.classList.toggle("bi-eye-slash", show);

      btn.setAttribute("aria-pressed", String(show));
      btn.setAttribute("aria-label", `${show ? "Ocultar" : "Mostrar"} ${btn.dataset.label}`);
      input.focus();
    });
  });

  // ---------- 2) Indicador de requisitos ----------
  function updatePasswordHint() {
    const met = password.value.length >= MIN_PASSWORD_LENGTH;
    hint.classList.toggle("is-met", met);
    hintIcon.classList.toggle("bi-check-circle-fill", met);
    hintIcon.classList.toggle("bi-circle", !met);
  }

  // ---------- 3) Coincidencia de contraseñas ----------
  function validateConfirm() {
    const mismatch = confirmPassword.value !== password.value;

    // Marca el campo como inválido para el navegador si no coinciden
    confirmPassword.setCustomValidity(mismatch ? "Las contraseñas no coinciden" : "");

    if (confirmPassword.value.length === 0) {
      // Campo vacío: sin feedback en vivo (el atributo required se encarga al enviar)
      confirmMsg.textContent = "Confirma tu contraseña.";
      confirmPassword.classList.remove("is-valid", "is-invalid");
      return;
    }

    confirmMsg.textContent = "Las contraseñas no coinciden.";
    confirmPassword.classList.toggle("is-invalid", mismatch);
    confirmPassword.classList.toggle("is-valid", !mismatch);
  }

  password.addEventListener("input", () => {
    updatePasswordHint();
    validateConfirm(); // si cambia la contraseña, vuelve a comparar
  });
  confirmPassword.addEventListener("input", validateConfirm);

  // ---------- 4) Envío ----------
  form.addEventListener("submit", (event) => {
    // Un nombre de solo espacios no es válido
    nombre.setCustomValidity(nombre.value.trim() ? "" : "Campo requerido");
    validateConfirm();

    if (!form.checkValidity()) {
      // Hay errores: se detiene el envío y se muestran los mensajes de Bootstrap
      event.preventDefault();
      event.stopPropagation();
      form.classList.add("was-validated");
      form.querySelector(":invalid").focus();
      return;
    }

    // Datos válidos: NO se llama a preventDefault(). El navegador envía el
    // formulario por POST (action) a controllers/seguridad/registro.php.
    // El servidor vuelve a validar todo: nunca se confía solo en el frontend.
  });
});