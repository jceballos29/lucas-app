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

  /* 4. Categorías según el tipo de movimiento (gasto / ingreso). El servidor pone TODAS las categorías en el <select>, cada
        una con data-tipo; aquí se dejan solo las del tipo elegido (tanto en #categoria como en las filas de división). Sin
        JS se ven todas y el servidor rechaza la que no corresponda (el tipo se comprueba siempre allí). */
  var tipoRadios = document.querySelectorAll('input[name="tipo"]');
  var selectoresCat = document.querySelectorAll('select#categoria, select[name="split_categoria[]"]');
  if (tipoRadios.length && selectoresCat.length) {
    var catalogos = Array.prototype.map.call(selectoresCat, function (sel) {
      return {
        sel: sel,
        todas: Array.prototype.map.call(sel.options, function (o) {
          return { value: o.value, text: o.textContent, tipo: o.getAttribute('data-tipo') };
        })
      };
    });
    var fill = function (tipo) {
      catalogos.forEach(function (item) {
        var sel = item.sel;
        var actual = sel.value;
        sel.innerHTML = '';
        item.todas.forEach(function (c) {
          if (c.tipo !== null && c.tipo !== tipo) return;
          var o = document.createElement('option');
          o.value = c.value; o.textContent = c.text;
          if (c.tipo !== null) o.setAttribute('data-tipo', c.tipo);
          if (c.value === actual) o.selected = true;
          sel.appendChild(o);
        });
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
  /* Color de una categoría (#RRGGBB): se valida antes de aplicarlo. */
  document.querySelectorAll('[data-color]').forEach(function (el) { if (/^#[0-9a-fA-F]{6}$/.test(el.dataset.color)) el.style.setProperty('--swatch', el.dataset.color); });
  document.querySelectorAll('[data-h]').forEach(function (el) { el.style.setProperty('--h', el.dataset.h); });

  /* 6. Formato de miles en campos de monto (visual): «1.250.000,50». Acepta una coma decimal con hasta 2 decimales (y un
        signo menos inicial en los campos que lo permiten, como el saldo inicial de una tarjeta de crédito); el servidor
        (Support\Dinero) entiende ese mismo formato. */
  function formatMoneyInput(inp) {
    var permiteNegativo = inp.getAttribute('data-money') === 'signed';
    var negativo = permiteNegativo && /^\s*[-−]/.test(inp.value);
    var limpio = inp.value.replace(/[^\d,]/g, '');
    var partes = limpio.split(',');
    var entero = partes[0].replace(/^0+(?=\d)/, '').slice(0, 10);   // DECIMAL(12,2): 10 dígitos enteros
    var texto = entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    if (partes.length > 1) texto += ',' + partes.slice(1).join('').slice(0, 2);
    inp.value = (negativo && (texto !== '' || inp.value.trim() !== '') ? '-' : '') + texto;
  }
  document.querySelectorAll('input[data-money]').forEach(function (inp) {
    if (inp.value !== '') formatMoneyInput(inp);
    inp.addEventListener('input', function () { formatMoneyInput(inp); });
  });

  /* 7. En el formulario de transferencias, al elegir la cuenta de origen se deshabilita esa misma cuenta en el selector de
        destino (y viceversa), evitando que el usuario elija la misma cuenta por error. */
  var selOrigen = document.getElementById('cuenta_origen');
  var selDestino = document.getElementById('cuenta_destino');
  if (selOrigen && selDestino) {
    var syncTransferAccounts = function () {
      var vOrigen = selOrigen.value;
      var vDestino = selDestino.value;
      Array.prototype.forEach.call(selDestino.options, function (opt) {
        opt.disabled = opt.value !== '' && opt.value === vOrigen;
      });
      Array.prototype.forEach.call(selOrigen.options, function (opt) {
        opt.disabled = opt.value !== '' && opt.value === vDestino;
      });
    };
    selOrigen.addEventListener('change', syncTransferAccounts);
    selDestino.addEventListener('change', syncTransferAccounts);
    syncTransferAccounts();
  }
})();
