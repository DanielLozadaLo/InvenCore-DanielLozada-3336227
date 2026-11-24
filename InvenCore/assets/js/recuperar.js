/**
 * InvenCore - Recuperar contraseña (frontend)
 *
 * La misma página tiene dos pasos, según la URL:
 *   - Sin ?token=  → PASO 1: pedir el enlace (correo → solicitar_recuperacion.php)
 *   - Con ?token=  → PASO 2: nueva contraseña (token oculto + contraseñas → actualizar_password.php)
 *
 * Además muestra los mensajes que devuelven los controladores (?error=, ?enviado=1, ?prueba=).
 * Si los datos son válidos, los formularios se envían por POST (sin preventDefault).
 */
document.addEventListener("DOMContentLoaded", () => {
  const MIN_PASSWORD_LENGTH = 8;
  const TOKEN_VALIDO = /^[a-f0-9]{64}$/;   // 64 caracteres hexadecimales

  const params = new URLSearchParams(window.location.search);

  /* ---------- Elementos ---------- */
  const formSolicitar = document.getElementById("formSolicitar");
  const formCambiar = document.getElementById("formCambiar");
  const subtitulo = document.getElementById("recSubtitulo");
  const errorAlert = document.getElementById("recError");
  const infoAlert = document.getElementById("recInfo");
  const simuladoBox = document.getElementById("recSimulado");
  const enlaceSimulado = document.getElementById("recEnlace");
  const tokenInput = document.getElementById("recToken");

  const password = document.getElementById("password");
  const confirmPassword = document.getElementById("confirmPassword");
  const confirmMsg = document.getElementById("confirmInvalidMsg");
  const hint = document.getElementById("passwordHint");

  // Mensajes para los códigos de error que envían los controladores
  const ERRORES_SERVIDOR = {
    correo_invalido: "Ingresa un correo electrónico válido.",
    campos_vacios: "Completa todos los campos.",
    password_corta: "La contraseña debe tener al menos 8 caracteres.",
    password_no_coincide: "Las contraseñas no coinciden.",
    token_invalido: "El enlace no es válido o ya expiró. Solicita uno nuevo.",
    error_servidor: "No pudimos completar la operación. Inténtalo de nuevo más tarde.",
  };

  /* =====================================================================
   * PASO 1 o PASO 2 según la URL
   * ===================================================================== */
  const tokenUrl = params.get("token");
  const modoCambiar = tokenUrl !== null && TOKEN_VALIDO.test(tokenUrl);

  if (modoCambiar) {
    tokenInput.value = tokenUrl;                       // El token viaja en un campo oculto
    formSolicitar.classList.add("d-none");
    formCambiar.classList.remove("d-none");
    subtitulo.textContent = "Establece tu nueva clave segura.";
  }

  /* =====================================================================
   * MENSAJES DEL SERVIDOR
   * ===================================================================== */
  function mostrarMensajesDelServidor() {
    const codigo = params.get("error");

    if (codigo) {
      // Solo se muestran mensajes de la lista (nunca texto arbitrario de la URL)
      errorAlert.textContent = ERRORES_SERVIDOR[codigo] || ERRORES_SERVIDOR.error_servidor;
      errorAlert.classList.remove("d-none");
    } else if (tokenUrl !== null && !modoCambiar) {
      // Llegó un ?token= con formato inválido
      errorAlert.textContent = ERRORES_SERVIDOR.token_invalido;
      errorAlert.classList.remove("d-none");
    }

    // Respuesta genérica: es la misma exista o no el correo
    if (params.get("enviado") === "1") {
      infoAlert.textContent = "Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña.";
      infoAlert.classList.remove("d-none");
    }

    // SOLO PRUEBAS LOCALES: enlace simulado (el controlador solo lo envía si MOSTRAR_ENLACE_SIMULADO = true)
    const prueba = params.get("prueba");
    if (prueba && TOKEN_VALIDO.test(prueba)) {
      const url = new URL(`recuperar-password.html?token=${prueba}`, window.location.href).href;
      enlaceSimulado.href = url;
      enlaceSimulado.textContent = url;
      simuladoBox.classList.remove("d-none");
    }

    // Limpia la barra de direcciones: solo se conserva el token en el paso 2 (hace falta al recargar)
    if (params.has("error") || params.has("enviado") || params.has("prueba")) {
      const consulta = modoCambiar ? `?token=${tokenUrl}` : "";
      window.history.replaceState(null, "", window.location.pathname + consulta);
    }
  }
  mostrarMensajesDelServidor();

  /* =====================================================================
   * PASO 1: validación del correo antes de enviar
   * ===================================================================== */
  formSolicitar.addEventListener("submit", (event) => {
    if (!formSolicitar.checkValidity()) {
      event.preventDefault();
      event.stopPropagation();
      formSolicitar.classList.add("was-validated");
      formSolicitar.querySelector(":invalid").focus();
    }
    // Si es válido, el navegador envía el POST a solicitar_recuperacion.php
  });

  /* =====================================================================
   * PASO 2: contraseñas
   * ===================================================================== */

  // Mostrar / ocultar contraseña
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

  // Indicador de requisitos: gris por defecto, verde al cumplirse
  function updatePasswordHint() {
    hint.classList.toggle("is-met", password.value.length >= MIN_PASSWORD_LENGTH);
  }

  // Coincidencia de contraseñas en vivo (is-valid / is-invalid)
  function validateConfirm() {
    const mismatch = confirmPassword.value !== password.value;

    // Si no coinciden, el campo es inválido para el navegador (bloquea el envío)
    confirmPassword.setCustomValidity(mismatch ? "Las contraseñas no coinciden" : "");

    if (confirmPassword.value.length === 0) {
      // Vacío: sin feedback en vivo; "required" se encarga al enviar
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
    validateConfirm(); // si cambia la nueva contraseña, vuelve a comparar
  });
  confirmPassword.addEventListener("input", validateConfirm);

  // Envío del cambio de contraseña
  formCambiar.addEventListener("submit", (event) => {
    validateConfirm();

    if (!formCambiar.checkValidity()) {
      event.preventDefault();
      event.stopPropagation();
      formCambiar.classList.add("was-validated");
      formCambiar.querySelector(":invalid").focus();
    }
    // Si es válido, el navegador envía el POST (con el token oculto) a actualizar_password.php,
    // que vuelve a validar todo en el servidor.
  });
});