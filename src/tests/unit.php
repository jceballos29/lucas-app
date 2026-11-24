<?php

declare(strict_types=1);

/**
 * Pruebas unitarias de la lógica pura del esqueleto (escape, origen, sesión, CSRF): sin BD ni servidor web.
 *
 *     docker compose exec php php tests/unit.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/harness.php';

section('escape()');
same('escapa HTML', '&lt;b&gt;&quot;x&quot;&#039;', escape('<b>"x"\''));

section('normalize_origin()');
same('minúsculas', 'http://localhost:8090', normalize_origin('HTTP://LOCALHOST:8090'));
same('descarta la ruta', 'http://localhost:8090', normalize_origin('http://localhost:8090/index.php?a=1'));
same('Origin con ruta no vale', null, normalize_origin('http://localhost:8090/x', true));
same('dominio engañoso', null, normalize_origin('http://localhost:8090.evil.com'));
same('"null" no vale', null, normalize_origin('null'));

section('session_is_expired()');
same('sin marca = caducada', true, session_is_expired(null, 1000));
same('reciente = viva', false, session_is_expired(900, 1000));
same('pasado el límite', true, session_is_expired(1000, 1000 + SESSION_IDLE_TIMEOUT));
same('marca del futuro', true, session_is_expired(2000, 1000));

section('format_utc_datetime()');
same('UTC a Bogotá', '2026-11-19 09:08:36', format_utc_datetime('2026-11-19 14:08:36', 'America/Bogota'));
same('fecha inválida se devuelve igual', 'nada', format_utc_datetime('nada'));

section('Ui: componentes (escape y seguridad)');
same('el texto del botón se escapa', true, str_contains((string) App\View\Ui::button('<b>x</b>'), '&lt;b&gt;x&lt;/b&gt;'));
same('un Html anidado no se escapa dos veces', '<span class="badge badge--neutral"><b>x</b></span>', (string) App\View\Ui::badge(new App\View\Html('<b>x</b>')));
same('un texto plano dentro de listItem se escapa', true, str_contains((string) App\View\Ui::listItem('<i>a</i>'), '&lt;i&gt;a&lt;/i&gt;'));
same('icono desconocido lanza excepción', true, (static function (): bool {
    try {
        App\View\Ui::icon('no-existe');
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());
same('variante de botón no válida lanza excepción', true, (static function (): bool {
    try {
        App\View\Ui::button('x', ['variant' => 'rojo']);
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());
same('un atributo con nombre malicioso se rechaza', true, (static function (): bool {
    try {
        App\View\Ui::button('x', ['attrs' => ['onclick="x"' => 'y']]);
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
})());
same('el valor de un atributo se escapa', true, str_contains((string) App\View\Ui::button('x', ['attrs' => ['data-a' => '"><script>']]), 'data-a="&quot;&gt;&lt;script&gt;"'));
same('los botones solo con icono llevan nombre accesible', true, str_contains((string) App\View\Ui::iconButton('edit', 'Editar Bus'), 'aria-label="Editar Bus"'));

section('Ui: dinero y progreso');
same('ingreso con signo y miles', '<span class="money money--in">+$1.800.000</span>', (string) App\View\Ui::money(1800000, 'in'));
same('gasto con signo menos tipográfico', '<span class="money money--out">−$18.000</span>', (string) App\View\Ui::money(18000, 'out'));
same('saldo negativo lleva signo', true, str_contains((string) App\View\Ui::money(-500), '−$500'));
same('pesos() da texto plano', '$2.450.000', App\View\Ui::pesos(2450000));
same('tono de presupuesto: en orden', 'ok', App\View\Ui::budgetTone(79));
same('tono de presupuesto: aviso al 80 %', 'warn', App\View\Ui::budgetTone(80));
same('tono de presupuesto: excedido al 100 %', 'over', App\View\Ui::budgetTone(100));
$bar = (string) App\View\Ui::progress(125, 'Ocio', 'auto');
same('la barra no pasa de 100 %', true, str_contains($bar, 'data-v="100"'));
same('pero aria-valuenow conserva el valor real', true, str_contains($bar, 'aria-valuenow="125"'));
same('el tono automático marca excedido', true, str_contains($bar, 'progress--over'));

section('Ui: campos de formulario');
$field = (string) App\View\Ui::field(['id' => 'correo', 'label' => 'Correo', 'error' => 'Falta el correo', 'hint' => 'Ayuda']);
same('la etiqueta apunta al control', true, str_contains($field, '<label class="field__label" for="correo">'));
same('un error marca aria-invalid', true, str_contains($field, 'aria-invalid="true"'));
same('el control apunta a la ayuda y al error', true, str_contains($field, 'aria-describedby="h-correo e-correo"'));
same('la contraseña nunca se vuelve a pintar', false, str_contains((string) App\View\Ui::field(['id' => 'c', 'label' => 'C', 'type' => 'password', 'value' => 'secreta']), 'secreta'));
same('el campo de contraseña trae su botón de mostrar', true, str_contains((string) App\View\Ui::field(['id' => 'c', 'label' => 'C', 'type' => 'password']), 'data-toggle-password="c"'));
$select = (string) App\View\Ui::select(['id' => 'f', 'label' => 'F', 'blank' => 'Todas', 'options' => ['a' => 'Uno', 'b' => 'Dos'], 'selected' => 'b']);
same('el select marca la opción elegida', true, str_contains($select, '<option value="b" selected>Dos</option>'));
same('el select trae la opción en blanco', true, str_contains($select, '<option value="">Todas</option>'));

section('Ui: gráficos y tablas');
$donut = (string) App\View\Ui::donut([['label' => 'A', 'value' => 60], ['label' => 'B', 'value' => 40]], 'Gráfico');
same('el donut dibuja una porción por dato', 2, substr_count($donut, 'donut__slice '));
same('la segunda porción arranca donde acaba la primera', true, str_contains($donut, 'stroke-dashoffset="-35"'));
$bars = (string) App\View\Ui::bars([['label' => 'a', 'value' => 50], ['label' => 'b', 'value' => 100]], 'Barras');
same('la barra mayor mide 100 %', true, str_contains($bars, 'data-h="100%"'));
same('la barra menor es proporcional', true, str_contains($bars, 'data-h="50%"'));
same('solo la última barra es la actual', 1, substr_count($bars, 'is-current'));
$table = (string) App\View\Ui::table(['caption' => 'T', 'columns' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B', 'kind' => 'actions']], 'rows' => [['a' => '<x>', 'b' => App\View\Ui::badge('ok')]]]);
same('las celdas de texto se escapan', true, str_contains($table, '&lt;x&gt;'));
same('las celdas Html pasan tal cual', true, str_contains($table, '<span class="badge'));
same('las celdas llevan su etiqueta para el móvil', true, str_contains($table, 'data-label="A"'));
same('la columna de acciones no lleva etiqueta', false, str_contains($table, 'data-label="B"'));

finish();
