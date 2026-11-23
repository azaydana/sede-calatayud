// Botón del ojo: enseña u oculta la contraseña cambiando el type del input.
// Sin JavaScript el formulario funciona igual; solo no se puede ver la contraseña.
document.querySelectorAll('button.ojo').forEach(function (boton) {
  var campo = document.getElementById(boton.getAttribute('aria-controls'));
  if (!campo) return;

  boton.addEventListener('click', function () {
    var mostrar = campo.type === 'password';
    campo.type = mostrar ? 'text' : 'password';
    boton.setAttribute('aria-pressed', String(mostrar));
    boton.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
    campo.focus();
  });

  // Al enviar, la contraseña vuelve a ocultarse (que no la guarde el navegador como texto visible).
  if (campo.form) {
    campo.form.addEventListener('submit', function () {
      campo.type = 'password';
    });
  }
});
