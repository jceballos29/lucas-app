/* Lucas · comportamiento del front-end (sin dependencias) */
(function () {
  'use strict';

  /* 1. Menú lateral en móvil/tableta */
  var sidebar = document.querySelector('.sidebar');
  var overlay = document.querySelector('.overlay');
  var menuBtn = document.querySelector('[data-menu]');
  function setMenu(open) {
    if (!sidebar) return;
    var wasOpen = sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', open);
    if (overlay) overlay.classList.toggle('is-open', open);
    if (menuBtn) {
      menuBtn.setAttribute('aria-expanded', String(open));
      menuBtn.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
      if (wasOpen && !open) menuBtn.focus();       // el foco vuelve al botón que abrió el menú
    }
  }
  if (menuBtn) menuBtn.addEventListener('click', function () { setMenu(!sidebar.classList.contains('is-open')); });
  if (overlay) overlay.addEventListener('click', function () { setMenu(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });

  /* 2. Filtros: abiertos en pantallas ≥768px, plegados en móvil */
  var filters = document.querySelector('details.filters');
  if (filters && window.matchMedia) {
    var mq = window.matchMedia('(min-width:768px)');
    var sync = function () { filters.open = mq.matches; };
    sync();
    (mq.addEventListener ? mq.addEventListener('change', sync) : mq.addListener(sync));
  }

  /* 3. Mostrar/ocultar contraseña (el icono lo cambia el CSS según aria-pressed) */
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
  });

  /* 4. Categorías según el tipo de movimiento (gasto / ingreso).
        Con PHP estas listas vendrán de la tabla "categorias". */
  var tipoRadios = document.querySelectorAll('input[name="tipo"]');
  var categoria = document.getElementById('categoria');
  if (tipoRadios.length && categoria) {
    var cats = {
      gasto: ['Alimentación', 'Transporte', 'Servicios', 'Entretenimiento', 'Salud', 'Educación', 'Otros'],
      ingreso: ['Salario', 'Ventas', 'Regalos', 'Otros ingresos']
    };
    var fill = function (tipo) {
      categoria.innerHTML = '';
      cats[tipo].forEach(function (n) {
        var o = document.createElement('option'); o.value = n; o.textContent = n; categoria.appendChild(o);
      });
    };
    tipoRadios.forEach(function (r) { r.addEventListener('change', function () { fill(r.value); }); });
    var checked = document.querySelector('input[name="tipo"]:checked');
    fill(checked ? checked.value : 'gasto');
  }

  /* 5. Diálogo de confirmación para acciones destructivas.
        Uso: <button data-confirm="¿Eliminar este movimiento?" data-confirm-ok="Eliminar"> dentro de un <form>.
        Con data-confirm-ok (texto del botón de confirmar) el botón se pinta como acción peligrosa. */
  var dlg;
  function getDialog() {
    if (dlg) return dlg;
    dlg = document.createElement('dialog');
    dlg.setAttribute('aria-labelledby', 'dlg-title');
    dlg.innerHTML = '<h2 id="dlg-title">Confirmar acción</h2><p class="muted" id="dlg-msg"></p>' +
      '<div class="dialog__actions"><button type="button" class="btn btn--secondary" data-no><span>Cancelar</span></button>' +
      '<button type="button" class="btn btn--dark" data-yes><span>Sí, continuar</span></button></div>';
    document.body.appendChild(dlg);
    return dlg;
  }
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      var d = getDialog();
      var ok = el.getAttribute('data-confirm-ok');
      var yes = d.querySelector('[data-yes]');
      d.querySelector('#dlg-msg').textContent = el.getAttribute('data-confirm');
      yes.querySelector('span').textContent = ok || 'Sí, continuar';
      yes.classList.toggle('btn--danger', !!ok);
      yes.classList.toggle('btn--dark', !ok);
      d.querySelector('[data-no]').onclick = function () { d.close(); };
      yes.onclick = function () {
        d.close();
        var form = el.closest('form');
        if (form) form.submit();                       // con PHP: envía el POST de eliminación
        else if (el.href) window.location.href = el.href;
      };
      d.showModal();
    });
  });

  /* 5b. Botón de envío: se desactiva y muestra el indicador mientras el formulario se envía (evita doble envío).
         setTimeout(0): así el navegador alcanza a incluir el valor del botón en la petición. */
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var btn = e.submitter || form.querySelector('button[type="submit"]');
      if (!btn || btn.hasAttribute('data-confirm') || form.method === 'get') return;
      setTimeout(function () { btn.setAttribute('aria-busy', 'true'); btn.disabled = true; }, 0);
    });
  });

  /* Al volver con «atrás» el navegador restaura la página tal cual: se reactivan los botones que quedaron «enviando». */
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    document.querySelectorAll('button[aria-busy="true"]').forEach(function (b) { b.removeAttribute('aria-busy'); b.disabled = false; });
  });

  /* 0. Valores dinámicos (barras, gráficos). No van en style="" porque la CSP (style-src 'self') bloquea los estilos en
        línea; asignar propiedades por JS (CSSOM) sí está permitido. */
  document.querySelectorAll('[data-v]').forEach(function (el) { el.style.setProperty('--v', Math.max(0, Math.min(100, Number(el.dataset.v) || 0)) + '%'); });
  document.querySelectorAll('[data-h]').forEach(function (el) { el.style.setProperty('--h', el.dataset.h); });

  /* 6. Formato de miles en campos de monto (visual). Con PHP, limpia el valor antes de guardar. */
  document.querySelectorAll('input[data-money]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var digits = inp.value.replace(/\D/g, '');
      inp.value = digits ? Number(digits).toLocaleString('es-CO') : '';
    });
  });
})();
