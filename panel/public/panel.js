// Panel del BIFI · comportamiento de todas las páginas. Todo es mejora progresiva: sin
// JavaScript los formularios se envían igual y el servidor valida igual.

// ---------- Botón del ojo: enseña u oculta la contraseña ----------
document.querySelectorAll('button.ojo').forEach((boton) => {
  const campo = document.getElementById(boton.getAttribute('aria-controls'));
  if (!campo) return;
  boton.addEventListener('click', () => {
    const mostrar = campo.type === 'password';
    campo.type = mostrar ? 'text' : 'password';
    boton.setAttribute('aria-pressed', String(mostrar));
    boton.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
    campo.focus();
  });
  // Al enviar vuelve a ocultarse, para que el navegador no la guarde como texto visible
  campo.form?.addEventListener('submit', () => { campo.type = 'password'; });
});

// ---------- Diálogos de confirmación ----------
// Los botones abren su <dialog> con command="show-modal" sin JavaScript. Donde el navegador aún no
// entiende command/commandfor, se abre con showModal().
if (!('commandForElement' in HTMLButtonElement.prototype)) {
  document.addEventListener('click', (e) => {
    const boton = e.target.closest('button[commandfor][command="show-modal"]');
    const dialogo = boton && document.getElementById(boton.getAttribute('commandfor'));
    if (dialogo instanceof HTMLDialogElement) dialogo.showModal();
  });
}

// ---------- Errores de los campos, también para lectores de pantalla ----------
// :user-invalid pinta el error solo cuando la persona ya ha tocado el campo. Aquí se sincroniza
// aria-invalid con ese estado y se enlaza el mensaje (data-error → id) en aria-describedby solo
// mientras se ve. Un aria-invalid="true" que viene del servidor se respeta.
const enlazarError = (campo, visible) => {
  const id = campo.dataset.error;
  if (!id) return;
  const ids = (campo.getAttribute('aria-describedby') || '').split(/\s+/).filter((x) => x && x !== id);
  if (visible) ids.push(id);
  if (ids.length) campo.setAttribute('aria-describedby', ids.join(' '));
  else campo.removeAttribute('aria-describedby');
};

// Una sola región viva y educada para anunciar el error al salir del campo
const anunciador = document.createElement('div');
anunciador.setAttribute('aria-live', 'polite');
anunciador.className = 'oculto';
document.body.append(anunciador);
let temporizador;

const anunciar = (campo) => {
  const error = document.getElementById(campo.dataset.error);
  if (!error) return;
  const etiqueta = campo.labels?.[0]?.firstChild?.textContent.trim() ?? '';
  clearTimeout(temporizador);
  anunciador.textContent = '';
  // Esperar a que el lector haya leído el campo siguiente
  temporizador = setTimeout(() => { anunciador.textContent = `${etiqueta}: ${error.textContent.trim()}`; }, 500);
};

const marcados = new WeakSet();
const anunciados = new WeakSet();
const sincronizar = (campo, avisar = false) => {
  if (!campo.matches?.('input, select, textarea') || !campo.dataset.error) return;
  if (campo.matches(':user-invalid')) {
    if (avisar && !anunciados.has(campo)) { anunciar(campo); anunciados.add(campo); }
    campo.setAttribute('aria-invalid', 'true');
    enlazarError(campo, true);
    marcados.add(campo);
  } else if (marcados.has(campo) || campo.getAttribute('aria-invalid') !== 'true') {
    campo.setAttribute('aria-invalid', 'false');
    enlazarError(campo, false);
    marcados.delete(campo);
    anunciados.delete(campo);
  }
};

document.querySelectorAll('[data-error]').forEach((campo) => sincronizar(campo));
document.addEventListener('blur', (e) => sincronizar(e.target, true), true);
document.addEventListener('change', (e) => sincronizar(e.target, true), true);
document.addEventListener('input', (e) => {
  const campo = e.target;
  // Un error del servidor (data-servidor) deja de valer en cuanto la persona toca el campo:
  // se desengancha de aria-describedby y a partir de ahí manda :user-invalid.
  if (campo.dataset?.servidor) {
    const ids = (campo.getAttribute('aria-describedby') || '').split(/\s+/).filter((x) => x && x !== campo.dataset.servidor);
    campo.setAttribute('aria-describedby', ids.join(' '));
    delete campo.dataset.servidor;
    marcados.add(campo);
    // Los desplegables no tienen validación propia: elegir otra opción ya corrige el error
    if (!campo.dataset.error) campo.setAttribute('aria-invalid', 'false');
  }
  if (campo.hasAttribute?.('aria-invalid')) sincronizar(campo);
});
// Un envío fallido dispara 'invalid' en cada campo mal; el foco va al primero y lee su error
document.addEventListener('invalid', (e) => { anunciados.add(e.target); sincronizar(e.target); }, true);
