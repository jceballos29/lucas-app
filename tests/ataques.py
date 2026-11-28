#!/usr/bin/env python3
"""
Pruebas de ataque contra la aplicación en marcha (docker compose up). Complementan a tests/smoke.sh: aquí se intenta ROMPER la
aplicación desde fuera (HTTP real), como lo haría un usuario malintencionado. Salen de la auditoría de docs/AUDITORIA.md.

    python3 tests/ataques.py             # ejecuta todo
    python3 tests/ataques.py --limpiar   # solo borra los datos de pruebas anteriores que hayan quedado

Qué cubre: aislamiento entre usuarios (IDOR), roles de espacios compartidos, escalada a admin, carreras de saldo y de token, XSS e
inyección, fuzzing de parámetros, subida de archivos maliciosos, sesión (fijación, reutilización), fuerza bruta, enumeración de
usuarios, cabeceras y exposición de archivos.

Hallazgos de docs/AUDITORIA.md que AÚN no están corregidos se marcan «CONOCIDO <id>» y NO hacen fallar la ejecución. Cuando se
corrija uno, la prueba pasa a imprimir «RESUELTO <id>»: entonces borra el argumento conocido= de esa comprobación para que, si
reaparece, falle de verdad.

Solo usa la librería estándar de Python. Crea usuarios `ataque_*@correo.com` (y espacios, movimientos y comprobantes) en la BD de
DESARROLLO y los borra al terminar. Usa docker compose para SQL (como smoke.sh). NUNCA lo apuntes a producción.
No forma parte de tests/run-all.sh por ahora: manda ~12 correos a Mailpit y consume cupo de registro por IP (20 cada 15 min).
"""

import concurrent.futures as cf
import base64
import hashlib
import hmac
import struct
import http.cookiejar
import json
import mimetypes
import os
import random
import re
import statistics
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

DIR = str(Path(__file__).resolve().parent.parent)
RUN = str(random.randint(100000, 999999))
CLAVE = 'Ataque-clave-2026'


def env_value(nombre: str, defecto: str) -> str:
    env = Path(DIR) / '.env'
    if env.is_file():
        for linea in env.read_text(encoding='utf-8').splitlines():
            if linea.startswith(nombre + '='):
                return linea.split('=', 1)[1].strip().strip('"\'')
    return defecto


PUERTO = env_value('APP_PORT', '8090')
BASE = f'http://localhost:{PUERTO}'
ORIGEN = env_value('APP_ORIGIN', BASE)
MAILPIT = f"http://127.0.0.1:{env_value('MAILPIT_PORT', '8025')}"

# ───────────────────────────── utilidades de BD y de salida ─────────────────────────────


def mysql(sql: str) -> str:
    r = subprocess.run(
        ['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c', 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 lucas -N 2>/dev/null'],
        input=sql, text=True, capture_output=True, cwd=DIR, errors='replace',
    )
    return r.stdout.strip()


def en_php(comando: str, env: dict | None = None) -> str:
    extra = [] if not env else [x for k, v in env.items() for x in ('-e', f'{k}={v}')]
    r = subprocess.run(['docker', 'compose', 'exec', '-T', *extra, 'php', 'sh', '-c', comando], capture_output=True, text=True, cwd=DIR, errors='replace')
    return r.stdout.strip()


ok = fallos = conocidos = resueltos = 0


def seccion(titulo: str) -> None:
    print(f'\n== {titulo}')


def chk(desc: str, cond: bool, detalle: str = '', conocido: str | None = None) -> bool:
    """Una comprobación. `conocido='A2'` marca un hallazgo de la auditoría aún sin corregir (no hace fallar)."""
    global ok, fallos, conocidos, resueltos
    if conocido:
        if cond:
            resueltos += 1
            print(f'  RESUELTO {conocido}  {desc}   <- ya se corrigió: quita conocido= de esta prueba')
        else:
            conocidos += 1
            print(f'  CONOCIDO {conocido}  {desc}   ({detalle})')
        return cond
    if cond:
        ok += 1
        print(f'  ok    {desc}')
    else:
        fallos += 1
        print(f'  FALLO {desc}' + (f'   ({detalle})' if detalle else ''))
    return cond


# ───────────────────────────── cliente HTTP (solo biblioteca estándar) ─────────────────────────────


class Resp:
    def __init__(self, status: int, headers, cuerpo: bytes):
        self.status = status
        self.headers = headers
        self.cuerpo = cuerpo
        self.texto = cuerpo.decode('utf-8', 'replace')

    @property
    def location(self) -> str:
        return self.headers.get('Location', '') or ''


class _SinRedireccion(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def _enviar(opener, metodo: str, url: str, cuerpo: bytes | None, cabeceras: dict) -> Resp:
    req = urllib.request.Request(url, data=cuerpo, method=metodo, headers=cabeceras)
    try:
        with opener.open(req, timeout=60) as r:
            return Resp(r.status, r.headers, r.read())
    except urllib.error.HTTPError as e:
        return Resp(e.code, e.headers, e.read() or b'')


def multipart(campos: dict, archivos: dict) -> tuple[bytes, str]:
    limite = '----ataque' + RUN
    partes = []
    for k, v in campos.items():
        partes.append(f'--{limite}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    for k, (nombre, contenido, tipo) in archivos.items():
        partes.append(f'--{limite}\r\nContent-Disposition: form-data; name="{k}"; filename="{nombre}"\r\nContent-Type: {tipo}\r\n\r\n'.encode() + contenido + b'\r\n')
    partes.append(f'--{limite}--\r\n'.encode())
    return b''.join(partes), f'multipart/form-data; boundary={limite}'


class Cliente:
    """Un navegador: guarda la cookie de sesión, lee el token CSRF de cada página y envía el Origin correcto."""

    def __init__(self) -> None:
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _SinRedireccion)

    def get(self, ruta: str, cabeceras: dict | None = None, metodo: str = 'GET') -> Resp:
        return _enviar(self.opener, metodo, BASE + ruta, None, cabeceras or {})

    def token(self, pagina: str) -> str:
        m = re.search(r'name="csrf_token" value="([a-f0-9]+)"', self.get(pagina).texto)
        return m.group(1) if m else ''

    def post(self, ruta: str, datos: dict, pagina: str | None = None, archivos: dict | None = None,
             origen: str | None = ORIGEN, token: str | None = None, con_token: bool = True) -> Resp:
        datos = dict(datos)
        if con_token:
            datos['csrf_token'] = token if token is not None else self.token(pagina or ruta.split('?')[0])
        cab = {'Origin': origen} if origen else {}
        if archivos:
            cuerpo, tipo = multipart(datos, archivos)
        else:
            cuerpo, tipo = urllib.parse.urlencode(datos).encode(), 'application/x-www-form-urlencoded'
        cab['Content-Type'] = tipo
        return _enviar(self.opener, 'POST', BASE + ruta, cuerpo, cab)

    def cookie(self) -> str:
        return next((c.value for c in self.jar if c.name == 'lucas_session'), '')


def sin_cookies(ruta: str, cookie: str) -> Resp:
    """GET con una cookie de sesión concreta y sin guardar nada (para probar fijación y reutilización)."""
    opener = urllib.request.build_opener(_SinRedireccion)
    return _enviar(opener, 'GET', BASE + ruta, None, {'Cookie': f'lucas_session={cookie}'})


def registrar(correo: str) -> Cliente:
    c = Cliente()
    c.post('/registro.php', {'nombre': 'Ataque Prueba', 'correo': correo, 'clave': CLAVE, 'clave2': CLAVE}, '/registro.php')
    return c


def iniciar(correo: str, clave: str = CLAVE) -> tuple[Cliente, Resp]:
    c = Cliente()
    return c, c.post('/login.php', {'correo': correo, 'clave': clave}, '/login.php')


def uid(correo: str) -> str:
    return mysql(f"SELECT id_usuario FROM usuarios WHERE correo='{correo}'")


# ───────────────────────────── limpieza ─────────────────────────────

LIKE = r"u.correo LIKE 'ataque\_%@correo.com'"


def limpiar() -> None:
    rutas = mysql(f"SELECT t.adjunto_ruta FROM transacciones t JOIN usuarios u ON u.id_usuario=t.id_usuario WHERE t.adjunto_ruta IS NOT NULL AND {LIKE}").split()
    for ruta in rutas:
        if re.fullmatch(r'\d{4}/\d{2}/[0-9a-f]{32}\.(jpg|png|webp|pdf)', ruta):
            en_php('rm -f "storage/adjuntos/$0"'.replace('$0', ruta))
    pasos = [
        f"DELETE x FROM pagos_deuda x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM aportes_meta x JOIN metas_ahorro m ON m.id_meta=x.id_meta JOIN usuarios u ON u.id_usuario=m.id_usuario WHERE {LIKE}",
        f"DELETE x FROM deudas x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM metas_ahorro x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM movimientos_recurrentes x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM transferencias x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM presupuestos x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM transacciones x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM cuentas x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM espacios x JOIN usuarios u ON u.id_usuario=x.id_creador WHERE {LIKE}",
        f"DELETE x FROM categorias x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM codigos_recuperacion x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE x FROM restablecimientos x JOIN usuarios u ON u.id_usuario=x.id_usuario WHERE {LIKE}",
        f"DELETE u FROM usuarios u WHERE {LIKE}",
        "DELETE FROM intentos_acceso",
        "DELETE FROM cola_correo WHERE destinatario LIKE 'ataque\\_%@correo.com'",
    ]
    for sql in pasos:
        mysql(sql)


# ───────────────────────────── datos de apoyo ─────────────────────────────

PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f00050001ff0b9d8d9d0000000049454e44ae426082')


def hoy_mes() -> str:
    return time.strftime('%Y-%m-%d')


def crear_cuenta(c: Cliente, nombre: str, saldo: str, tipo: str = 'efectivo', extra: dict | None = None) -> str:
    c.post('/cuenta-form.php', {'nombre': nombre, 'tipo': tipo, 'saldo_inicial': saldo, 'moneda': 'COP', **(extra or {})})
    return ''


def id_cuenta(usuario_id: str, nombre: str) -> str:
    return mysql(f"SELECT id_cuenta FROM cuentas WHERE id_usuario={usuario_id} AND nombre='{nombre}' ORDER BY id_cuenta DESC LIMIT 1")


def mov(c: Cliente, cuenta: str, categoria: str, monto: str = '100', desc: str = 'mov', extra: dict | None = None, archivos: dict | None = None) -> Resp:
    return c.post('/movimiento-form.php', {'tipo': 'gasto', 'monto': monto, 'fecha': hoy_mes(), 'id_cuenta': cuenta, 'id_categoria': categoria, 'descripcion': desc, **(extra or {})}, archivos=archivos)


# ═════════════════════════════════════════ PRUEBAS ═════════════════════════════════════════


def pruebas_idor(A: Cliente, B: Cliente, ea: str, eb: str) -> None:
    seccion('Aislamiento entre usuarios (IDOR): B intenta tocar los objetos de A')
    uA, uB = uid(ea), uid(eb)
    cat_g = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='gasto' ORDER BY id_categoria LIMIT 1")
    cat_i = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='ingreso' ORDER BY id_categoria LIMIT 1")
    crear_cuenta(A, 'CuentaA', '1000000'); crear_cuenta(A, 'CuentaA2', '500'); crear_cuenta(B, 'CuentaB', '100')
    cA, cA2, cB = id_cuenta(uA, 'CuentaA'), id_cuenta(uA, 'CuentaA2'), id_cuenta(uB, 'CuentaB')
    A.post('/categorias.php', {'accion': 'crear', 'nombre': 'CatPrivA', 'tipo': 'gasto', 'color': '#112233'})
    catA = mysql(f"SELECT id_categoria FROM categorias WHERE id_usuario={uA}")
    mov(A, cA, cat_g, '100', 'movA', archivos={'comprobante': ('a.png', PNG, 'image/png')})
    mA = mysql(f"SELECT id_transaccion FROM transacciones WHERE id_usuario={uA}")
    A.post('/meta-form.php', {'nombre': 'MetaA', 'monto_objetivo': '1000', 'fecha_limite': '2099-12-01'})
    metaA = mysql(f"SELECT id_meta FROM metas_ahorro WHERE id_usuario={uA}")
    A.post('/deuda-form.php', {'nombre': 'DeudaA', 'acreedor': 'X', 'monto_inicial': '1000', 'tasa_mensual': '1', 'cuota_minima': '10', 'dia_pago': '5', 'id_cuenta': cA})
    dA = mysql(f"SELECT id_deuda FROM deudas WHERE id_usuario={uA}")
    A.post('/transferencia-form.php', {'id_cuenta_origen': cA, 'id_cuenta_destino': cA2, 'monto': '10', 'fecha': hoy_mes()})
    tA = mysql(f"SELECT id_transferencia FROM transferencias WHERE id_usuario={uA}")
    A.post('/recurrente-form.php', {'tipo': 'gasto', 'monto': '5', 'frecuencia': 'mensual', 'proxima_fecha': '2099-12-01', 'id_cuenta': cA, 'id_categoria': cat_g, 'descripcion': 'recA'})
    rA = mysql(f"SELECT id_recurrente FROM movimientos_recurrentes WHERE id_usuario={uA}")
    A.post('/presupuesto-form.php', {'id_categoria': cat_g, 'mes': '11', 'anio': '2026', 'monto_limite': '100'})
    pA = mysql(f"SELECT id_presupuesto FROM presupuestos WHERE id_usuario={uA}")
    chk('los datos de A se crearon (si no, esta sección no prueba nada)', all([cA, cA2, cB, catA, mA, metaA, dA, tA, rA, pA]), f'{cA}/{mA}/{metaA}/{dA}/{tA}/{rA}/{pA}')

    antes = mysql(f"SELECT (SELECT COUNT(*) FROM transacciones WHERE id_usuario={uA}),(SELECT COUNT(*) FROM cuentas WHERE id_usuario={uA}),(SELECT COUNT(*) FROM transferencias WHERE id_usuario={uA}),(SELECT COUNT(*) FROM metas_ahorro WHERE id_usuario={uA}),(SELECT COUNT(*) FROM deudas WHERE id_usuario={uA}),(SELECT COUNT(*) FROM presupuestos WHERE id_usuario={uA}),(SELECT COUNT(*) FROM movimientos_recurrentes WHERE id_usuario={uA}),(SELECT SUM(monto) FROM transacciones WHERE id_usuario={uA})")
    hoy = hoy_mes()
    intentos = [
        ('crear movimiento en cuenta de A', lambda: mov(B, cA, cat_g, '1', 'hack')),
        ('usar la categoría privada de A', lambda: mov(B, cB, catA, '1', 'hack2')),
        ('editar el movimiento de A', lambda: B.post('/movimiento-form.php', {'id': mA, 'tipo': 'gasto', 'monto': '1', 'fecha': hoy, 'id_cuenta': cB, 'id_categoria': cat_g, 'descripcion': 'hack3'})),
        ('eliminar el movimiento de A', lambda: B.post('/movimiento-eliminar.php', {'id': mA}, '/movimientos.php')),
        ('descargar el comprobante de A', lambda: B.get(f'/adjunto.php?id={mA}')),
        ('transferir desde una cuenta de A', lambda: B.post('/transferencia-form.php', {'id_cuenta_origen': cA, 'id_cuenta_destino': cB, 'monto': '999', 'fecha': hoy})),
        ('transferir hacia una cuenta de A', lambda: B.post('/transferencia-form.php', {'id_cuenta_origen': cB, 'id_cuenta_destino': cA, 'monto': '10', 'fecha': hoy})),
        ('editar la transferencia de A', lambda: B.post('/transferencia-form.php', {'id': tA, 'id_cuenta_origen': cB, 'id_cuenta_destino': cB, 'monto': '10', 'fecha': hoy})),
        ('eliminar la transferencia de A', lambda: B.post('/transferencias.php', {'id': tA, 'accion': 'eliminar'}, '/transferencias.php')),
        ('aportar a la meta de A', lambda: B.post(f'/meta-aporte.php?id={metaA}', {'id': metaA, 'id_cuenta': cB, 'monto': '1', 'fecha': hoy})),
        ('eliminar la meta de A', lambda: B.post('/metas.php', {'id': metaA, 'accion': 'eliminar'}, '/metas.php')),
        ('pagar la deuda de A', lambda: B.post(f'/deuda-pago.php?id={dA}', {'id': dA, 'id_cuenta': cB, 'monto_total': '10', 'fecha': hoy, 'modo_pago': 'cuota'})),
        ('eliminar la deuda de A', lambda: B.post('/deudas.php', {'id': dA, 'accion': 'eliminar'}, '/deudas.php')),
        ('crear un recurrente con cuenta de A', lambda: B.post('/recurrente-form.php', {'tipo': 'gasto', 'monto': '5', 'frecuencia': 'mensual', 'proxima_fecha': '2099-12-01', 'id_cuenta': cA, 'id_categoria': cat_g})),
        ('aplicar el recurrente de A', lambda: B.post('/recurrentes.php', {'id': rA, 'accion': 'aplicar'}, '/recurrentes.php')),
        ('editar el presupuesto de A', lambda: B.post('/presupuesto-form.php', {'id': pA, 'id_categoria': cat_g, 'mes': '11', 'anio': '2026', 'monto_limite': '1'})),
        ('eliminar el presupuesto de A', lambda: B.post('/presupuestos.php', {'id': pA, 'accion': 'eliminar'}, '/presupuestos.php')),
        ('eliminar la categoría de A', lambda: B.post('/categorias.php', {'accion': 'eliminar', 'id': catA}, '/categorias.php')),
        ('renombrar la categoría de A', lambda: B.post('/categorias.php', {'accion': 'guardar', 'id': catA, 'nombre': 'pwn', 'tipo': 'gasto', 'color': '#000000'}, '/categorias.php')),
        ('conciliar la cuenta de A', lambda: B.post(f'/cuenta-conciliar.php?id={cA}', {'id': cA, 'saldo_real': '0', 'fecha': hoy})),
        ('desactivar la cuenta de A', lambda: B.post('/cuentas.php', {'id': cA, 'accion': 'desactivar'}, '/cuentas.php')),
        ('abrir el formulario de la cuenta de A', lambda: B.get(f'/cuenta-form.php?id={cA}')),
        ('importar un CSV a la cuenta de A', lambda: B.post('/movimientos-importar.php', {'id_cuenta': cA, 'id_categoria_gasto': cat_g, 'id_categoria_ingreso': cat_i, 'csv_texto': f'{hoy};x;-5'}, '/movimientos-importar.php')),
    ]
    for desc, fn in intentos:
        r = fn()
        chk(f'B no puede {desc}', r.status in (400, 403, 404) or (r.status == 200 and r.status != 302), f'HTTP {r.status} {r.location}')
    despues = mysql(f"SELECT (SELECT COUNT(*) FROM transacciones WHERE id_usuario={uA}),(SELECT COUNT(*) FROM cuentas WHERE id_usuario={uA}),(SELECT COUNT(*) FROM transferencias WHERE id_usuario={uA}),(SELECT COUNT(*) FROM metas_ahorro WHERE id_usuario={uA}),(SELECT COUNT(*) FROM deudas WHERE id_usuario={uA}),(SELECT COUNT(*) FROM presupuestos WHERE id_usuario={uA}),(SELECT COUNT(*) FROM movimientos_recurrentes WHERE id_usuario={uA}),(SELECT SUM(monto) FROM transacciones WHERE id_usuario={uA})")
    chk('los datos de A quedaron exactamente igual tras todos los intentos', antes == despues and antes != '', f'{antes} -> {despues}')
    chk('ninguna fila de B apunta a objetos de A', mysql(f"SELECT COUNT(*) FROM transacciones WHERE id_usuario={uB} AND (id_cuenta={cA} OR id_categoria={catA})") == '0')
    chk('el comprobante de A sí lo ve A (200)', A.get(f'/adjunto.php?id={mA}').status == 200)
    chk('B no puede ver el comprobante de A (404, no 403: no revela que existe)', B.get(f'/adjunto.php?id={mA}').status == 404)


def pruebas_privilegios(B: Cliente, eb: str, ea: str) -> None:
    seccion('Escalada de privilegios y mass assignment')
    for p in ('/admin/index.php', '/admin/usuarios.php', '/admin/categorias.php'):
        r = B.get(p)
        chk(f'usuario normal recibe 403 en {p}', r.status == 403, f'HTTP {r.status}')
    r = B.post('/admin/usuarios.php', {'id': uid(ea), 'accion': 'hacer_admin'}, '/perfil.php')
    chk('un usuario normal no puede hacer admin a otro', r.status == 403 and mysql(f"SELECT rol FROM usuarios WHERE correo='{ea}'") == 'usuario', f'HTTP {r.status}')
    B.post('/perfil.php', {'accion': 'datos', 'nombre': 'Otro nombre', 'moneda': 'COP', 'rol': 'admin', 'activo': '1', 'id_usuario': '1'}, '/perfil.php')
    chk('un campo «rol» enviado a mano en el perfil se ignora', mysql(f"SELECT rol FROM usuarios WHERE correo='{eb}'") == 'usuario')
    gid = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND id_espacio IS NULL ORDER BY id_categoria LIMIT 1")
    nombre = mysql(f"SELECT nombre FROM categorias WHERE id_categoria={gid}")
    r1 = B.post('/categorias.php', {'accion': 'guardar', 'id': gid, 'nombre': 'hackglobal', 'tipo': 'gasto', 'color': '#000000'}, '/categorias.php')
    r2 = B.post('/categorias.php', {'accion': 'eliminar', 'id': gid}, '/categorias.php')
    chk('un usuario normal no edita ni borra categorías globales', r1.status == 403 and r2.status == 403 and mysql(f"SELECT nombre FROM categorias WHERE id_categoria={gid}") == nombre, f'{r1.status}/{r2.status}')
    r = registrar_raw('ataque_' + RUN + '_rolreg@correo.com', {'rol': 'admin'})
    chk('registrarse con rol=admin crea un usuario normal (RF-25)', mysql(f"SELECT rol FROM usuarios WHERE correo='ataque_{RUN}_rolreg@correo.com'") == 'usuario')


def registrar_raw(correo: str, extra: dict) -> Resp:
    c = Cliente()
    return c.post('/registro.php', {'nombre': 'Ataque Prueba', 'correo': correo, 'clave': CLAVE, 'clave2': CLAVE, **extra}, '/registro.php')


def pruebas_carreras(A: Cliente, ea: str) -> None:
    seccion('Carreras: peticiones simultáneas no deben crear dinero')
    uA = uid(ea)
    cat_g = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='gasto' ORDER BY id_categoria LIMIT 1")
    crear_cuenta(A, 'Carrera1', '100'); crear_cuenta(A, 'Carrera2', '100'); crear_cuenta(A, 'Destino', '0')
    c1, c2, dest = id_cuenta(uA, 'Carrera1'), id_cuenta(uA, 'Carrera2'), id_cuenta(uA, 'Destino')
    tok = A.token('/movimiento-form.php')
    with cf.ThreadPoolExecutor(16) as ex:
        list(ex.map(lambda i: A.post('/movimiento-form.php', {'tipo': 'gasto', 'monto': '100', 'fecha': hoy_mes(), 'id_cuenta': c1, 'id_categoria': cat_g, 'descripcion': f'carrera{i}'}, token=tok), range(16)))
    n = mysql(f"SELECT COUNT(*) FROM transacciones WHERE id_cuenta={c1}")
    chk('16 gastos simultáneos de $100 con saldo $100 → solo 1 se guarda', n == '1', f'guardados: {n}')
    tok = A.token('/transferencia-form.php')
    with cf.ThreadPoolExecutor(16) as ex:
        list(ex.map(lambda i: A.post('/transferencia-form.php', {'id_cuenta_origen': c2, 'id_cuenta_destino': dest, 'monto': '100', 'fecha': hoy_mes()}, token=tok), range(16)))
    n = mysql(f"SELECT COUNT(*) FROM transferencias WHERE id_cuenta_origen={c2}")
    chk('16 transferencias simultáneas de $100 con saldo $100 → solo 1 se guarda', n == '1', f'guardadas: {n}')


def pruebas_entradas(A: Cliente, ea: str) -> None:
    seccion('XSS, inyección y fuzzing de parámetros')
    uA = uid(ea)
    cat_g = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='gasto' ORDER BY id_categoria LIMIT 1")
    crear_cuenta(A, 'Fuzz', '1000000')
    cf_ = id_cuenta(uA, 'Fuzz')
    xss = '"><svg onload=alert(1)> <img src=x onerror=alert(2)>'
    mov(A, cf_, cat_g, '1', xss, {'etiqueta': 'x'})
    crear_cuenta(A, '<b>Negrita</b>"\'', '1')
    paginas = ['/movimientos.php', '/cuentas.php', '/movimiento-form.php', '/transferencia-form.php', '/reportes.php', '/reporte-imprimir.php', '/panel.php']
    html = ''.join(A.get(p).texto for p in paginas)
    chk('el HTML inyectado en una descripción nunca aparece sin escapar', '<svg onload' not in html and '<img src=x' not in html and '&lt;svg onload' in html)
    chk('un nombre de cuenta con <b> se escapa en todas las pantallas', '<b>Negrita</b>' not in html and '&lt;b&gt;Negrita' in html)
    r = A.get('/movimientos.php?etiqueta=%22%3E%3Cscript%3E1%3C/script%3E&q=%3Csvg/onload=1%3E')
    chk('los filtros de la URL no se reflejan sin escapar', '<script>1' not in r.texto and '<svg/onload' not in r.texto)
    for payload in ["'; DROP TABLE usuarios;--", '\x00nul', '😀' * 100, 'A' * 5000, '<script>1</script>']:
        r = A.post('/categorias.php', {'accion': 'crear', 'nombre': payload, 'tipo': 'gasto', 'color': '#aabbcc'}, '/categorias.php')
        chk(f'categoría con nombre {payload[:14]!r} no provoca error 500', r.status < 500, f'HTTP {r.status}')
    chk('la tabla usuarios sigue existiendo tras el intento de inyección SQL', mysql('SELECT COUNT(*) FROM usuarios') not in ('', '0'))
    raras = ['/movimientos.php?pagina=-1', '/movimientos.php?pagina=0', '/movimientos.php?pagina=99999999999999999999999', '/movimientos.php?desde[]=1&tipo[]=x&hasta=zzz',
             '/reportes.php?mes=13&anio=99999', '/reportes.php?mes[]=1', '/presupuestos.php?mes=0&anio=-5', '/reporte-imprimir.php?mes=abc',
             '/cuenta-form.php?id[]=1', '/cuenta-form.php?id=abc', '/movimiento-form.php?id=1e3', '/movimiento-form.php?id=99999999999999999999', '/panel.php?mes=99',
             '/adjunto.php?id=-1', '/adjunto.php?id=../../etc/passwd', '/exportar.php?desde=zzz&tipo[]=1']
    malos = [p for p in raras if A.get(p).status >= 500]
    chk(f'{len(raras)} URLs con parámetros absurdos no provocan ningún error 500', not malos, str(malos))
    malos = []
    for m in ['0', '-5', '9999999999999', '1e9', '1.000.000.000.000', 'NaN', '٣', '1,2,3', '  ', '+5', '--5', '1.2.3', "1' OR '1'='1"]:
        r = mov(A, cf_, cat_g, m, 'monto raro')
        if r.status >= 500:
            malos.append(m)
    chk('montos inválidos (cero, negativos, enormes, texto) no provocan error 500', not malos, str(malos))
    cuotas_antes = mysql(f"SELECT COUNT(*) FROM cuotas_tarjeta")
    crear_cuenta(A, 'Tarjeta', '0', 'tarjeta_credito', {'dia_corte': '10', 'dia_pago_limite': '25', 'tasa_mensual': '2'})
    tj = id_cuenta(uA, 'Tarjeta')
    malos = []
    for nc in ['1000000', '99999', '361', '-1', '0', 'abc']:
        r = mov(A, tj, cat_g, '1000', 'cuotas', {'num_cuotas': nc, 'con_intereses': '1'})
        if r.status >= 500 or r.status == 302:
            malos.append(nc)
    chk('un número de cuotas absurdo se rechaza (no crea miles de filas)', not malos and mysql('SELECT COUNT(*) FROM cuotas_tarjeta') == cuotas_antes, str(malos))
    for metodo in ('TRACE',):
        chk(f'el método {metodo} no está permitido', Cliente().get('/login.php', metodo=metodo).status in (405, 501))


def pruebas_archivos(A: Cliente, ea: str) -> None:
    seccion('Subida de archivos maliciosos')
    uA = uid(ea)
    cat_g = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='gasto' ORDER BY id_categoria LIMIT 1")
    crear_cuenta(A, 'Archivos', '1000000')
    ca = id_cuenta(uA, 'Archivos')
    rechazados = [
        ('shell.php', b'<?php system($_GET[0]);', 'application/x-php'),
        ('s.svg', b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/svg+xml'),
        ('g.gif', b'GIF89a;<?php', 'image/gif'),
        ('vacio.png', b'', 'image/png'),
        ('html.png', b'<html><script>alert(1)</script>', 'image/png'),
    ]
    for nombre, contenido, tipo in rechazados:
        antes = mysql(f"SELECT COUNT(*) FROM transacciones WHERE id_cuenta={ca}")
        r = mov(A, ca, cat_g, '1', 'sube ' + nombre, archivos={'comprobante': (nombre, contenido, tipo)})
        chk(f'se rechaza {nombre} (el contenido manda, no la extensión ni el tipo declarado)', r.status == 200 and mysql(f"SELECT COUNT(*) FROM transacciones WHERE id_cuenta={ca}") == antes, f'HTTP {r.status}')
    aceptados = [
        ('ok.png', PNG, 'image/png'),
        ('x.png.php', PNG + b'<?php phpinfo();?>', 'image/png'),
        ('poly.jpg', b'\xff\xd8\xff\xe0<?php system(1);?>', 'image/jpeg'),
        ('../../../etc/x.png', PNG, 'image/png'),
        ('e.pdf', b'%PDF-1.4\n/JS (app.alert(1))\n%%EOF', 'application/pdf'),
    ]
    for nombre, contenido, tipo in aceptados:
        mov(A, ca, cat_g, '1', 'sube ' + nombre, archivos={'comprobante': (nombre, contenido, tipo)})
    rutas = mysql(f"SELECT adjunto_ruta FROM transacciones WHERE id_cuenta={ca} AND adjunto_ruta IS NOT NULL").split()
    chk('los archivos con firma válida se guardaron', len(rutas) == len(aceptados), f'{len(rutas)} de {len(aceptados)}')
    chk('todas las rutas guardadas son YYYY/MM/<32 hex>.<jpg|png|webp|pdf> (nombre aleatorio, extensión canónica, sin ../)',
        all(re.fullmatch(r'\d{4}/\d{2}/[0-9a-f]{32}\.(jpg|png|webp|pdf)', x) for x in rutas), str(rutas))
    chk('ningún archivo ejecutable (.php) quedó guardado en el almacén', en_php('find storage/adjuntos -name "*.php*" | wc -l') == '0')
    chk('el almacén de comprobantes no es accesible por URL', Cliente().get('/storage/adjuntos/' + (rutas[0] if rutas else 'x')).status in (301, 302, 403, 404))
    id_mov = mysql(f"SELECT id_transaccion FROM transacciones WHERE id_cuenta={ca} AND adjunto_ruta IS NOT NULL ORDER BY id_transaccion LIMIT 1")
    r = A.get(f'/adjunto.php?id={id_mov}')
    chk('el comprobante se sirve con Content-Type real y X-Content-Type-Options: nosniff', r.headers.get('Content-Type', '').startswith('image/png') and r.headers.get('X-Content-Type-Options') == 'nosniff', r.headers.get('Content-Type', ''))
    chk('el comprobante no se guarda en caché pública', 'no-store' in (r.headers.get('Cache-Control') or ''))
    r = mov(A, ca, cat_g, '1', 'grande', archivos={'comprobante': ('big.png', PNG + b'0' * (7 * 1024 * 1024), 'image/png')})
    chk('un archivo de 7 MB se rechaza (413 de nginx o error de validación)', r.status in (200, 413), f'HTTP {r.status}')
    csp = A.get(f'/adjunto.php?id={id_mov}').headers.get('Content-Security-Policy') or ''
    chk('los comprobantes llevan CSP con sandbox y default-src none (nada puede ejecutarse ni cargarse)', 'sandbox' in csp and "default-src 'none'" in csp, csp or 'falta la cabecera')
    id_pdf = mysql(f"SELECT id_transaccion FROM transacciones WHERE id_cuenta={ca} AND adjunto_mime='application/pdf' ORDER BY id_transaccion LIMIT 1")
    r = A.get(f'/adjunto.php?id={id_pdf}')
    chk('un PDF (puede llevar JavaScript) siempre se descarga: Content-Disposition attachment', (r.headers.get('Content-Disposition') or '').startswith('attachment'), r.headers.get('Content-Disposition', ''))
    chk('y también lleva la CSP con sandbox', 'sandbox' in (r.headers.get('Content-Security-Policy') or ''))
    chk('las imágenes siguen mostrándose en el navegador (inline)', (A.get(f'/adjunto.php?id={id_mov}').headers.get('Content-Disposition') or '').startswith('inline'))


def pruebas_importacion(A: Cliente, B: Cliente, ea: str, eb: str) -> None:
    seccion('Importación CSV: vista previa, manipulación y abuso (M8/U4)')
    uA, uB = uid(ea), uid(eb)
    crear_cuenta(A, 'Cuenta Import', '100000')
    ca = id_cuenta(uA, 'Cuenta Import')
    cat_g = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='gasto' ORDER BY id_categoria LIMIT 1")
    cat_i = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='ingreso' ORDER BY id_categoria LIMIT 1")
    hoy = hoy_mes()
    base = {'id_cuenta': ca, 'id_categoria_gasto': cat_g, 'id_categoria_ingreso': cat_i}
    n0 = mysql(f"SELECT COUNT(*) FROM transacciones WHERE id_cuenta={ca}")
    r = A.post('/movimientos-importar.php', {**base, 'csv_texto': f'{hoy};Previa;-100'}, '/movimientos-importar.php')
    chk('enviar un CSV solo previsualiza: no guarda nada', r.status == 200 and 'Revisa antes de importar' in r.texto and mysql(f"SELECT COUNT(*) FROM transacciones WHERE id_cuenta={ca}") == n0)
    r = B.post('/movimientos-importar.php', {**base, 'accion': 'confirmar', 'incluir[]': '1', 'csv_texto': f'{hoy};Intruso;-100'}, '/movimientos-importar.php')
    chk('B no puede previsualizar ni confirmar sobre la cuenta de A (404) y no se crea nada', r.status == 404 and mysql(f"SELECT COUNT(*) FROM transacciones WHERE id_cuenta={ca}") == n0, f'HTTP {r.status}')
    r = A.post('/movimientos-importar.php', {**base, 'accion': 'confirmar', 'incluir[]': '1', 'csv_texto': f'{hoy};Honesto;-250', 'monto': '1', 'filas': '[{"monto":"1"}]'}, '/movimientos-importar.php')
    chk('al confirmar solo cuenta el texto CSV: campos extra («monto», «filas») se ignoran', mysql(f"SELECT monto FROM transacciones WHERE id_cuenta={ca} AND descripcion='Honesto'") == '250.00', mysql(f"SELECT monto FROM transacciones WHERE id_cuenta={ca} AND descripcion='Honesto'"))
    r = A.post('/movimientos-importar.php', {**base, 'accion': 'confirmar', 'incluir[]': ['x', '-1', '1e3', '99999999999'], 'csv_texto': f'{hoy};Raro;-1'}, '/movimientos-importar.php')
    chk('números de línea manipulados no importan nada ni rompen', r.status == 200 and mysql(f"SELECT COUNT(*) FROM transacciones WHERE descripcion='Raro'") == '0', f'HTTP {r.status}')
    basura = '\n'.join(f'basura {i};x;y' for i in range(20000))
    t0 = time.time()
    r = A.post('/movimientos-importar.php', {**base, 'csv_texto': basura}, '/movimientos-importar.php')
    chk('un CSV de 20.000 líneas basura se rechaza rápido y la página lista como mucho 50 problemas', r.status == 200 and time.time() - t0 < 8 and r.texto.count('<li>Línea') <= 50 and 'problemas más' in r.texto, f'{time.time() - t0:.1f} s, {r.texto.count("<li>Línea")} líneas')
    r = A.post('/movimientos-importar.php', {**base, 'csv_texto': f'{hoy};<script>alert(1)</script>;-5'}, '/movimientos-importar.php')
    chk('la vista previa escapa el HTML de la descripción', '<script>alert(1)</script>' not in r.texto and '&lt;script&gt;' in r.texto)


def pruebas_espacios(E: Cliente, F: Cliente, B: Cliente, ee: str, ef: str) -> None:
    seccion('Espacios compartidos: roles y abuso')
    uE, uF = uid(ee), uid(ef)
    mysql(f"UPDATE usuarios SET correo_verificado_en = NOW() WHERE correo IN ('{ee}', '{ef}')")   # A1: el registro nace sin verificar; la verificación real se prueba en pruebas_correo
    cat_g = mysql("SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo='gasto' ORDER BY id_categoria LIMIT 1")
    E.post('/espacios.php', {'accion': 'crear', 'nombre': 'Fam' + RUN, 'moneda': 'COP'})
    esp = mysql(f"SELECT id_espacio FROM espacios WHERE nombre='Fam{RUN}'")
    chk('E creó un espacio compartido', esp != '')
    E.post('/espacios.php', {'accion': 'invitar', 'id_espacio': esp, 'correo': ef, 'rol': 'lector'}, '/espacios.php')
    inv = mysql(f"SELECT id_invitacion FROM espacio_invitaciones WHERE id_espacio={esp} AND correo='{ef}'")
    F.post('/espacios.php', {'accion': 'aceptar_invitacion', 'id_invitacion': inv}, '/espacios.php')
    F.post('/espacios.php', {'accion': 'cambiar', 'id_espacio': esp}, '/espacios.php')
    chk('F (lector) está dentro del espacio', mysql(f"SELECT rol FROM espacio_miembros WHERE id_espacio={esp} AND id_usuario={uF}") == 'lector')

    E.post('/cuenta-form.php', {'nombre': 'CuentaEsp', 'tipo': 'efectivo', 'saldo_inicial': '1000', 'moneda': 'COP'})
    cesp = mysql(f"SELECT id_cuenta FROM cuentas WHERE id_espacio={esp} LIMIT 1")
    antes = mysql(f"SELECT (SELECT COUNT(*) FROM cuentas WHERE id_espacio={esp})+(SELECT COUNT(*) FROM categorias WHERE id_espacio={esp})+(SELECT COUNT(*) FROM metas_ahorro WHERE id_espacio={esp})+(SELECT COUNT(*) FROM presupuestos WHERE id_espacio={esp})+(SELECT COUNT(*) FROM deudas WHERE id_espacio={esp})+(SELECT COUNT(*) FROM tasas_cambio WHERE id_espacio={esp})+(SELECT COUNT(*) FROM transacciones WHERE id_espacio={esp})+(SELECT COUNT(*) FROM movimientos_recurrentes WHERE id_espacio={esp})")
    escrituras = [
        ('crear cuenta', '/cuenta-form.php', {'nombre': 'LectorHack', 'tipo': 'efectivo', 'saldo_inicial': '1', 'moneda': 'COP'}),
        ('crear categoría', '/categorias.php', {'accion': 'crear', 'nombre': 'LectorCat', 'tipo': 'gasto', 'color': '#000000'}),
        ('crear meta', '/meta-form.php', {'nombre': 'm', 'monto_objetivo': '1', 'fecha_limite': '2099-01-01'}),
        ('crear presupuesto', '/presupuesto-form.php', {'id_categoria': cat_g, 'mes': '11', 'anio': '2026', 'monto_limite': '5'}),
        ('crear deuda', '/deuda-form.php', {'nombre': 'd', 'acreedor': 'x', 'monto_inicial': '1', 'tasa_mensual': '0', 'cuota_minima': '1', 'dia_pago': '1'}),
        ('registrar un movimiento', '/movimiento-form.php', {'tipo': 'gasto', 'monto': '1', 'fecha': hoy_mes(), 'id_cuenta': cesp, 'id_categoria': cat_g}),
        ('importar CSV', '/movimientos-importar.php', {'id_cuenta': cesp, 'id_categoria_gasto': cat_g, 'id_categoria_ingreso': cat_g, 'csv_texto': f'{hoy_mes()};x;-1'}),
        ('guardar una tasa de cambio', '/cuentas.php', {'accion': 'guardar_tasa', 'moneda_origen': 'USD', 'moneda_destino': 'COP', 'tasa': '4000'}),
        ('crear un recurrente', '/recurrente-form.php', {'tipo': 'gasto', 'monto': '1', 'frecuencia': 'mensual', 'proxima_fecha': '2099-12-01', 'id_cuenta': cesp, 'id_categoria': cat_g}),
        ('crear una transferencia', '/transferencia-form.php', {'id_cuenta_origen': cesp, 'id_cuenta_destino': cesp, 'monto': '1', 'fecha': hoy_mes()}),
    ]
    for desc, ruta, datos in escrituras:
        r = F.post(ruta, datos)
        chk(f'un lector no puede {desc} (403)', r.status == 403, f'HTTP {r.status}')
    despues = mysql(antes and f"SELECT (SELECT COUNT(*) FROM cuentas WHERE id_espacio={esp})+(SELECT COUNT(*) FROM categorias WHERE id_espacio={esp})+(SELECT COUNT(*) FROM metas_ahorro WHERE id_espacio={esp})+(SELECT COUNT(*) FROM presupuestos WHERE id_espacio={esp})+(SELECT COUNT(*) FROM deudas WHERE id_espacio={esp})+(SELECT COUNT(*) FROM tasas_cambio WHERE id_espacio={esp})+(SELECT COUNT(*) FROM transacciones WHERE id_espacio={esp})+(SELECT COUNT(*) FROM movimientos_recurrentes WHERE id_espacio={esp})")
    chk('el lector no dejó ninguna fila nueva en el espacio', antes == despues, f'{antes} -> {despues}')
    for accion, datos in [('cambiar_rol', {'id_usuario': uF, 'rol': 'propietario'}), ('eliminar_espacio', {}), ('invitar', {'correo': 'x@y.com', 'rol': 'editor'})]:
        r = F.post('/espacios.php', {'accion': accion, 'id_espacio': esp, **datos}, '/espacios.php')
        chk(f'un lector no puede «{accion}» (403)', r.status == 403, f'HTTP {r.status}')
    chk('el lector sigue siendo lector', mysql(f"SELECT rol FROM espacio_miembros WHERE id_espacio={esp} AND id_usuario={uF}") == 'lector')
    r = B.post('/espacios.php', {'accion': 'cambiar', 'id_espacio': esp}, '/espacios.php')
    chk('quien no es miembro no puede activar un espacio ajeno (404)', r.status == 404, f'HTTP {r.status}')

    # A2 · abuso de invitaciones
    aceptadas = 0
    for i in range(12):
        r = E.post('/espacios.php', {'accion': 'invitar', 'id_espacio': esp, 'correo': f'inv{i}_{RUN}@ejemplo.org', 'rol': 'lector'}, '/espacios.php')
        aceptadas += r.status == 302
    chk('un usuario no puede invitar a un número ilimitado de correos externos (tope de 10 al día; ya había enviado 1)', aceptadas <= 9, f'se aceptaron {aceptadas}/12 sin límite')

    # M3 · comprobantes al eliminar el espacio
    E.post('/movimiento-form.php', {'tipo': 'gasto', 'monto': '1', 'fecha': hoy_mes(), 'id_cuenta': cesp, 'id_categoria': cat_g, 'descripcion': 'con comprobante'},
           archivos={'comprobante': ('r.png', PNG, 'image/png')})
    ruta = mysql(f"SELECT adjunto_ruta FROM transacciones WHERE id_espacio={esp} AND adjunto_ruta IS NOT NULL LIMIT 1")
    chk('el movimiento del espacio guardó su comprobante en disco', ruta != '' and en_php(f'test -f "storage/adjuntos/{ruta}" && echo si') == 'si', ruta)
    E.post('/espacios.php', {'accion': 'eliminar_espacio', 'id_espacio': esp}, '/espacios.php')
    chk('eliminar el espacio borra sus datos', mysql(f"SELECT COUNT(*) FROM espacios WHERE id_espacio={esp}") == '0')
    sigue = en_php(f'test -f "storage/adjuntos/{ruta}" && echo si || echo no') == 'si'
    chk('eliminar el espacio también borra los comprobantes del disco (RNF-20)', not sigue, f'queda storage/adjuntos/{ruta}')
    if sigue:
        en_php(f'rm -f "storage/adjuntos/{ruta}"')


def pruebas_sesion(ea: str) -> None:
    seccion('Sesión')
    r = sin_cookies('/login.php', 'fijadaPorElAtacante1234567890ab')
    nueva = re.search(r'lucas_session=([^;]+)', r.headers.get('Set-Cookie') or '')
    chk('un id de sesión impuesto por el atacante no se acepta (strict mode)', bool(nueva) and nueva.group(1) != 'fijadaPorElAtacante1234567890ab', str(r.headers.get('Set-Cookie')))
    anonimo = Cliente(); anonimo.get('/login.php')
    chk('la cookie de sesión es HttpOnly y SameSite=Lax', 'HttpOnly' in (Cliente().get('/login.php').headers.get('Set-Cookie') or '') and 'SameSite=Lax' in (Cliente().get('/login.php').headers.get('Set-Cookie') or ''))
    c, _ = iniciar(ea)
    viejo = c.cookie()
    chk('con sesión iniciada /panel.php responde 200', c.get('/panel.php').status == 200)
    c.post('/logout.php', {}, '/panel.php')
    r = sin_cookies('/panel.php', viejo)
    chk('la cookie de una sesión cerrada ya no sirve (302 a login)', r.status == 302 and '/login.php' in r.location, f'HTTP {r.status}')
    c2, _ = iniciar(ea)
    chk('un POST sin token CSRF se rechaza (403)', c2.post('/perfil.php', {'accion': 'datos', 'nombre': 'x', 'moneda': 'COP'}, con_token=False).status == 403)
    chk('un POST desde otro origen se rechaza (403)', c2.post('/perfil.php', {'accion': 'datos', 'nombre': 'x', 'moneda': 'COP'}, '/perfil.php', origen='http://evil.example').status == 403)
    chk('un POST sin Origin ni Referer se rechaza (403)', c2.post('/perfil.php', {'accion': 'datos', 'nombre': 'x', 'moneda': 'COP'}, '/perfil.php', origen=None).status == 403)
    n = int(mysql(f"SELECT COUNT(*) FROM sesiones_usuario s JOIN usuarios u USING(id_usuario) WHERE u.correo='{ea}'") or 0)
    for _ in range(12):
        iniciar(ea)
    n2 = int(mysql(f"SELECT COUNT(*) FROM sesiones_usuario s JOIN usuarios u USING(id_usuario) WHERE u.correo='{ea}'") or 0)
    chk('hay un tope de sesiones simultáneas por usuario (≤ 10)', n2 <= 10, f'{n2} sesiones abiertas')


def pruebas_correo(ev: str) -> None:
    seccion('Correo sin verificar (A1): registrar el correo de otra persona no da nada')
    eg = f'ataque_{RUN}_g@correo.com'
    G = registrar(eg)
    chk('un usuario recién registrado tiene el correo SIN verificar', mysql(f"SELECT correo_verificado_en IS NOT NULL FROM usuarios WHERE correo='{eg}'") == '0')
    G.post('/espacios.php', {'accion': 'crear', 'nombre': 'Hogar' + RUN, 'moneda': 'COP'})
    esp = mysql(f"SELECT id_espacio FROM espacios WHERE nombre='Hogar{RUN}'")
    G.post('/espacios.php', {'accion': 'invitar', 'id_espacio': esp, 'correo': ev, 'rol': 'editor'}, '/espacios.php')
    chk('sin verificar su correo, G no puede invitar a nadie', mysql(f"SELECT COUNT(*) FROM espacio_invitaciones WHERE id_espacio={esp}") == '0')
    r = G.post('/perfil.php', {'accion': 'notificaciones', 'notif_presupuestos': '1', 'notif_resumen_mensual': '1'}, '/perfil.php')
    chk('sin verificar, no se pueden activar los avisos por correo', mysql(f"SELECT notif_presupuestos + notif_resumen_mensual FROM usuarios WHERE correo='{eg}'") == '0', f'HTTP {r.status}')
    mysql(f"UPDATE usuarios SET correo_verificado_en = NOW() WHERE correo='{eg}'")
    G.post('/espacios.php', {'accion': 'invitar', 'id_espacio': esp, 'correo': ev, 'rol': 'editor'}, '/espacios.php')
    inv = mysql(f"SELECT id_invitacion FROM espacio_invitaciones WHERE id_espacio={esp} AND correo='{ev}'")
    chk('ya verificado, G sí puede invitar a la dirección de la víctima', inv != '')

    # El atacante se registra con el correo de la víctima (sin ser su dueño)
    X = registrar(ev)
    chk('el atacante pudo registrar el correo de la víctima, pero queda SIN verificar', mysql(f"SELECT correo_verificado_en IS NOT NULL FROM usuarios WHERE correo='{ev}'") == '0')
    chk('sin verificar no ve las invitaciones dirigidas a esa dirección', 'Hogar' + RUN not in X.get('/espacios.php').texto)
    r = X.post('/espacios.php', {'accion': 'aceptar_invitacion', 'id_invitacion': inv}, '/espacios.php')
    uX = uid(ev)
    chk('ni puede aceptarla: no entra al espacio y la invitación sigue pendiente',
        mysql(f"SELECT COUNT(*) FROM espacio_miembros WHERE id_espacio={esp} AND id_usuario={uX}") == '0' and mysql(f"SELECT COUNT(*) FROM espacio_invitaciones WHERE id_invitacion={inv}") == '1', f'HTTP {r.status}')
    r = X.post('/perfil.php', {'accion': 'resumen_prueba'}, '/perfil.php')
    chk('tampoco puede usar a Lucas para enviarle correo (resumen de prueba)', mysql(f"SELECT COUNT(*) FROM notificaciones_enviadas WHERE id_usuario={uX} AND clave_dedup LIKE 'resumen-prueba%'") == '0')

    # La víctima REAL abre el enlace que llegó a su buzón (el registro lo envía a esa dirección)
    token = ''
    try:
        for _ in range(40):
            time.sleep(0.25)
            ids = json.loads(urllib.request.urlopen(MAILPIT + '/api/v1/search?query=' + urllib.parse.quote('to:' + ev), timeout=5).read())['messages']
            for m in ids:
                cuerpo = json.loads(urllib.request.urlopen(MAILPIT + '/api/v1/message/' + m['ID'], timeout=5).read())['Text']
                t = re.search(r'verificar\.php\?token=([a-f0-9]{64})', cuerpo)
                if t:
                    token = t.group(1)
            if token:
                break
    except Exception:
        print('  (verificación por correo omitida: Mailpit no responde en ' + MAILPIT + ')')
        return
    chk('el correo de verificación llegó a la dirección registrada', token != '')
    if not token:
        return
    anonimo = Cliente()
    chk('abrir el enlace (GET) solo muestra el botón: no verifica', 'Confirmar mi correo' in anonimo.get('/verificar.php?token=' + token).texto and mysql(f"SELECT correo_verificado_en IS NOT NULL FROM usuarios WHERE correo='{ev}'") == '0')
    chk('confirmar sin token CSRF se rechaza', anonimo.post('/verificar.php', {'token': token}, con_token=False).status == 403)
    r = anonimo.post('/verificar.php', {'token': token}, '/verificar.php?token=' + token)
    chk('confirmar con el botón verifica el correo y lleva a iniciar sesión', r.status == 302 and 'verified' in r.location and mysql(f"SELECT correo_verificado_en IS NOT NULL FROM usuarios WHERE correo='{ev}'") == '1', f'HTTP {r.status} {r.location}')
    chk('el enlace es de un solo uso', 'no es válido' in Cliente().get('/verificar.php?token=' + token).texto)
    X.post('/espacios.php', {'accion': 'aceptar_invitacion', 'id_invitacion': inv}, '/espacios.php')
    chk('ya verificado, la invitación sí se puede aceptar', mysql(f"SELECT COUNT(*) FROM espacio_miembros WHERE id_espacio={esp} AND id_usuario={uX}") == '1')


def totp(secreto: str, desplazamiento: int = 0) -> str:
    """Código TOTP (RFC 6238, SHA-1, 6 dígitos, 30 s) calculado por el atacante/usuario con el secreto en base32."""
    clave = base64.b32decode(secreto.replace(' ', '').upper() + '=' * (-len(secreto.replace(' ', '')) % 8))
    paso = int(time.time()) // 30 + desplazamiento
    h = hmac.new(clave, struct.pack('>Q', paso), hashlib.sha1).digest()
    o = h[19] & 15
    return str((struct.unpack('>I', h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000).zfill(6)


def pruebas_dos_pasos(eh: str) -> None:
    seccion('Verificación en dos pasos (M7)')
    H = registrar(eh)
    otra, _ = iniciar(eh)   # una segunda sesión abierta ANTES de activar el 2FA
    chk('antes de activar, la segunda sesión funciona', otra.get('/panel.php').status == 200)
    H.post('/perfil.php', {'accion': 'dos_pasos_iniciar'}, '/perfil.php')
    m = re.search(r'id="dp-secreto">([A-Z2-7 ]+)<', H.get('/perfil.php').texto)
    secreto = (m.group(1) if m else '').replace(' ', '')
    chk('la configuración muestra un secreto de 32 caracteres base32', len(secreto) == 32, secreto)
    if len(secreto) != 32:
        return
    r = H.post('/perfil.php', {'accion': 'dos_pasos_activar', 'codigo': '000000' if totp(secreto) != '000000' else '111111'}, '/perfil.php')
    chk('un código incorrecto NO activa el 2FA', mysql(f"SELECT totp_activado_en IS NOT NULL FROM usuarios WHERE correo='{eh}'") == '0', f'HTTP {r.status}')
    r = H.post('/perfil.php', {'accion': 'dos_pasos_activar', 'codigo': totp(secreto)}, '/perfil.php')
    codigos = re.findall(r'<code>([A-Z0-9]{4}(?:-[A-Z0-9]{4}){3})</code>', r.texto)
    chk('el código correcto activa el 2FA y entrega 10 códigos de recuperación distintos', mysql(f"SELECT totp_activado_en IS NOT NULL FROM usuarios WHERE correo='{eh}'") == '1' and len(set(codigos)) == 10, f'{len(codigos)} códigos')
    chk('el secreto no se guarda en claro en la base de datos', secreto not in mysql(f"SELECT totp_secreto FROM usuarios WHERE correo='{eh}'"))
    guardados = mysql(f"SELECT GROUP_CONCAT(codigo_hash) FROM codigos_recuperacion c JOIN usuarios u USING(id_usuario) WHERE u.correo='{eh}'")
    chk('los códigos de recuperación tampoco (solo su hash SHA-256)', bool(codigos) and not any(c.replace('-', '') in guardados or c in guardados for c in codigos) and len(guardados.split(',')) == 10)
    chk('al activar el 2FA se cierran las demás sesiones abiertas', otra.get('/panel.php').status == 302)
    chk('la sesión que activó el 2FA sigue abierta', H.get('/panel.php').status == 200)

    # ── inicio de sesión ──
    c, r = iniciar(eh)
    chk('con 2FA, la contraseña correcta lleva al segundo paso (no abre sesión)', r.status == 302 and '/login-2fa.php' in r.location, f'HTTP {r.status} {r.location}')
    chk('con solo la contraseña NO se puede entrar al panel', c.get('/panel.php').status == 302 and '/login.php' in c.get('/panel.php').location)
    chk('ni ver el perfil ni los datos', c.get('/perfil.php').status == 302 and c.get('/movimientos.php').status == 302)
    sid_reto = c.cookie()
    r = c.post('/login-2fa.php', {'codigo': totp(secreto, 1)}, '/login-2fa.php', con_token=False)
    chk('el segundo paso sin token CSRF se rechaza (403)', r.status == 403, f'HTTP {r.status}')
    r = c.post('/login-2fa.php', {'codigo': totp(secreto, 1)}, '/login-2fa.php', origen='http://evil.example')
    chk('el segundo paso desde otro origen se rechaza (403)', r.status == 403)
    mal = '000000' if totp(secreto, 1) != '000000' else '111111'
    r = c.post('/login-2fa.php', {'codigo': mal}, '/login-2fa.php')
    chk('un código incorrecto no entra y explica el error', r.status == 200 and 'no es correcto' in r.texto and c.get('/panel.php').status == 302)
    cod_usado = totp(secreto, 1)   # se guarda: si el reloj cruza un paso de 30 s entre el uso y la repetición, «el mismo código» seguiría siendo el mismo
    r = c.post('/login-2fa.php', {'codigo': cod_usado}, '/login-2fa.php')
    chk('el código correcto abre la sesión', r.status == 302 and 'panel' in r.location and c.get('/panel.php').status == 200, f'HTTP {r.status} {r.location}')
    chk('el id de sesión cambia al completar el segundo paso (anti-fijación)', c.cookie() != sid_reto and sin_cookies('/panel.php', sid_reto).status == 302)

    c2, _ = iniciar(eh)
    r = c2.post('/login-2fa.php', {'codigo': cod_usado}, '/login-2fa.php')
    chk('un código TOTP ya usado no sirve otra vez (repetición bloqueada)', r.status == 200 and c2.get('/panel.php').status == 302, f'HTTP {r.status}')
    mysql(f"UPDATE usuarios SET totp_ultimo_paso = NULL WHERE correo='{eh}'")

    # ── códigos de recuperación ──
    c3, _ = iniciar(eh)
    r = c3.post('/login-2fa.php', {'codigo': codigos[0].lower()}, '/login-2fa.php')
    chk('un código de recuperación (aun en minúsculas) abre sesión y avisa que se gastó', r.status == 302 and 'perfil.php' in r.location and c3.get('/panel.php').status == 200, f'HTTP {r.status} {r.location}')
    c4, _ = iniciar(eh)
    r = c4.post('/login-2fa.php', {'codigo': codigos[0]}, '/login-2fa.php')
    chk('el mismo código de recuperación no sirve dos veces', r.status == 200 and c4.get('/panel.php').status == 302)

    # ── fuerza bruta del segundo paso ──
    mysql('DELETE FROM intentos_acceso')
    c5, _ = iniciar(eh)
    estados = []
    for i in range(6):
        estados.append(c5.post('/login-2fa.php', {'codigo': f'{(i * 7919 + 13) % 1000000:06d}' if f'{(i * 7919 + 13) % 1000000:06d}' != totp(secreto) else '999999'}, '/login-2fa.php').status)
    chk('tras 5 códigos erróneos el reto se descarta: ya no hay segundo paso que adivinar', estados[:4] == [200] * 4 and estados[4] == 302 and estados[5] == 302, str(estados))
    mysql('DELETE FROM intentos_acceso')
    for i in range(11):
        cc, _r = iniciar(eh)
        cc.post('/login-2fa.php', {'codigo': '12345' + str(i % 10)}, '/login-2fa.php')
    cc, _r = iniciar(eh)
    r = cc.post('/login-2fa.php', {'codigo': totp(secreto, 1)}, '/login-2fa.php')
    chk('reiniciar el reto una y otra vez no evita el límite de intentos (→ 429)', r.status == 429, f'HTTP {r.status}')
    mysql('DELETE FROM intentos_acceso')
    mysql(f"UPDATE usuarios SET totp_ultimo_paso = NULL WHERE correo='{eh}'")

    # ── desactivar ──
    r = H.post('/perfil.php', {'accion': 'dos_pasos_desactivar', 'dp_clave': 'incorrecta', 'codigo': totp(secreto, 1)}, '/perfil.php')
    chk('desactivar con la contraseña equivocada no funciona', mysql(f"SELECT totp_activado_en IS NOT NULL FROM usuarios WHERE correo='{eh}'") == '1')
    r = H.post('/perfil.php', {'accion': 'dos_pasos_desactivar', 'dp_clave': CLAVE, 'codigo': '000001'}, '/perfil.php')
    chk('desactivar con la contraseña pero sin un código válido tampoco', mysql(f"SELECT totp_activado_en IS NOT NULL FROM usuarios WHERE correo='{eh}'") == '1')
    mysql('DELETE FROM intentos_acceso')
    r = H.post('/perfil.php', {'accion': 'dos_pasos_regenerar', 'dp_clave': CLAVE, 'codigo': totp(secreto, 1)}, '/perfil.php')
    nuevos = re.findall(r'<code>([A-Z0-9]{4}(?:-[A-Z0-9]{4}){3})</code>', r.texto)
    chk('con contraseña y código se generan 10 códigos nuevos y los viejos dejan de valer', len(set(nuevos)) == 10 and not set(nuevos) & set(codigos))
    mysql(f"UPDATE usuarios SET totp_ultimo_paso = NULL WHERE correo='{eh}'")
    r = H.post('/perfil.php', {'accion': 'dos_pasos_desactivar', 'dp_clave': CLAVE, 'codigo': totp(secreto, 1)}, '/perfil.php')
    chk('con contraseña y código válidos se desactiva el 2FA y se borran los códigos', mysql(f"SELECT totp_activado_en IS NULL AND totp_secreto IS NULL FROM usuarios WHERE correo='{eh}'") == '1' and mysql(f"SELECT COUNT(*) FROM codigos_recuperacion c JOIN usuarios u USING(id_usuario) WHERE u.correo='{eh}'") == '0')
    c6, r = iniciar(eh)
    chk('sin 2FA el login vuelve a ser solo contraseña', r.status == 302 and 'panel' in r.location)


def pruebas_acceso(D: str) -> None:
    seccion('Fuerza bruta y enumeración de usuarios')
    mysql('DELETE FROM intentos_acceso')
    codigos = [Cliente().post('/login.php', {'correo': D, 'clave': f'mala{i}'}, '/login.php').status for i in range(6)]
    chk('tras 5 intentos fallidos el 6.º recibe 429', codigos[:5] == [200] * 5 and codigos[5] == 429, str(codigos))
    mysql('DELETE FROM intentos_acceso')

    def fallos_desde(ip: str, n: int, correo: str = D) -> None:
        """Simula n fallos de login desde otra IP (el cliente de prueba solo tiene una), con las mismas claves que usa la app."""
        h = lambda t: hashlib.sha256(t.encode()).hexdigest()
        for _ in range(n):
            for clave in (f'correo:{h(correo.lower())}', f'ip:{ip}', f'par:{h(correo.lower() + chr(10) + ip)}'):
                mysql(f"INSERT INTO intentos_acceso (tipo, clave) VALUES ('login', '{clave}')")

    fallos_desde('203.0.113.7', 5)
    c, r = iniciar(D)
    chk('el dueño puede entrar aunque otra persona (otra IP) haya fallado 5 veces con su correo', r.status == 302, f'HTTP {r.status}: el atacante bloquea la cuenta')
    mysql('DELETE FROM intentos_acceso')
    fallos_desde('203.0.113.7', 5)
    fallos_desde('203.0.113.8', 5)
    fallos_desde('203.0.113.9', 5)
    c, r = iniciar(D)
    chk('varios atacantes con pocos fallos cada uno tampoco bloquean al dueño', r.status == 302, f'HTTP {r.status}')
    mysql('DELETE FROM intentos_acceso')
    for i in range(10):
        fallos_desde(f'198.51.100.{i}', 5)
    c, r = iniciar(D)
    chk('50 fallos con el mismo correo desde IP rotatorias sí bloquean (el límite por correo sigue existiendo, pero alto)', r.status == 429, f'HTTP {r.status}')
    mysql('DELETE FROM intentos_acceso')

    def mediana(correo: str) -> float:
        xs = []
        for _ in range(4):
            cl = Cliente(); tok = cl.token('/login.php'); t0 = time.time()
            cl.post('/login.php', {'correo': correo, 'clave': 'x' * 10}, token=tok); xs.append(time.time() - t0)
        return statistics.median(xs)
    existe, no_existe = mediana(D), mediana(f'ataque_{RUN}_noexiste@correo.com')
    chk('el login tarda lo mismo con un correo existente que con uno inexistente (no se enumeran usuarios)', abs(existe - no_existe) < 0.15, f'{existe:.3f}s vs {no_existe:.3f}s')
    r1 = Cliente().post('/login.php', {'correo': D, 'clave': 'incorrecta'}, '/login.php')
    r2 = Cliente().post('/login.php', {'correo': f'ataque_{RUN}_noexiste2@correo.com', 'clave': 'incorrecta'}, '/login.php')
    chk('el mensaje de error del login es el mismo exista o no el correo', 'Correo o contraseña incorrectos' in r1.texto and 'Correo o contraseña incorrectos' in r2.texto)
    mysql('DELETE FROM intentos_acceso')


def pruebas_recuperacion(D: str) -> None:
    seccion('Recuperación de contraseña')
    try:
        urllib.request.urlopen(MAILPIT + '/api/v1/messages', timeout=3).read()
    except Exception:
        print('  (omitido: Mailpit no responde en ' + MAILPIT + ')')
        return
    Cliente().post('/recuperar.php', {'correo': D}, '/recuperar.php')
    token = ''
    for _ in range(40):
        time.sleep(0.25)
        try:
            ids = json.loads(urllib.request.urlopen(MAILPIT + '/api/v1/search?query=' + urllib.parse.quote('to:' + D), timeout=5).read())['messages']
        except Exception:
            ids = []
        for msg in ids:
            cuerpo = json.loads(urllib.request.urlopen(MAILPIT + '/api/v1/message/' + msg['ID'], timeout=5).read())['Text']
            m = re.search(r'restablecer\.php\?token=([a-f0-9]{64})', cuerpo)   # (el registro también envía un correo, de verificación)
            if m:
                token = m.group(1)
                break
        if token:
            break
    chk('se recibió el correo con el enlace de recuperación', token != '')
    if not token:
        return

    def usar(i: int) -> int:
        c = Cliente()
        return c.post('/restablecer.php', {'token': token, 'clave': f'Nueva-clave-{i}-2026', 'clave2': f'Nueva-clave-{i}-2026'}, f'/restablecer.php?token={token}').status
    with cf.ThreadPoolExecutor(8) as ex:
        codigos = list(ex.map(usar, range(8)))
    chk('8 usos simultáneos del mismo enlace → solo 1 cambia la contraseña', codigos.count(302) == 1, str(codigos))
    falso = Cliente()
    r = falso.post('/restablecer.php', {'token': 'a' * 64, 'clave': CLAVE, 'clave2': CLAVE}, token=falso.token('/login.php'))   # el token CSRF es de toda la sesión
    chk('un enlace inventado no cambia ninguna contraseña (vuelve a mostrar el formulario, no redirige a login)', r.status == 200 and 'msg=reset' not in r.location, f'HTTP {r.status} {r.location}')


def pruebas_exposicion() -> None:
    seccion('Cabeceras y archivos expuestos')
    c = Cliente()
    h = c.get('/login.php').headers
    csp = h.get('Content-Security-Policy') or ''
    chk('CSP presente y sin unsafe-inline ni unsafe-eval', csp != '' and 'unsafe-inline' not in csp and 'unsafe-eval' not in csp)
    chk('X-Frame-Options: DENY y frame-ancestors none (anti-clickjacking)', h.get('X-Frame-Options') == 'DENY' and "frame-ancestors 'none'" in csp)
    chk('X-Content-Type-Options: nosniff', h.get('X-Content-Type-Options') == 'nosniff')
    chk('Referrer-Policy y Permissions-Policy definidas', bool(h.get('Referrer-Policy')) and bool(h.get('Permissions-Policy')))
    chk('no se publica la versión de PHP ni de nginx', not h.get('X-Powered-By') and not re.search(r'\d', h.get('Server') or ''), f"{h.get('Server')} / {h.get('X-Powered-By')}")
    for ruta, esperados in [('/.env', (403, 404)), ('/.git/config', (403, 404)), ('/vendor/autoload.php', (403, 404)), ('/bootstrap.php', (404,)), ('/app/Container.php', (404,)),
                            ('/composer.json', (302, 403, 404)), ('/login.php/x.php', (404,)), ('/no-existe.php', (404,))]:
        r = c.get(ruta)
        chk(f'{ruta} no es accesible', r.status in esperados and '<?php' not in r.texto and 'DB_PASSWORD' not in r.texto, f'HTTP {r.status}')
    if env_value('APP_ENV', 'local') != 'local':
        chk('/status.php no existe fuera de entorno local', c.get('/status.php').status == 404)
    # M2 (resuelto): la imagen de producción se construye sin Xdebug y, por si acaso, con XDEBUG_MODE=off. (No se puede probar con el
    # contenedor en marcha, que es el de desarrollo y lo lleva a propósito; por eso se comprueba la configuración de producción.)
    prod_compose = (Path(DIR) / 'docker-compose.prod.yml').read_text(encoding='utf-8')
    dockerfile = (Path(DIR) / 'docker' / 'php' / 'Dockerfile').read_text(encoding='utf-8')
    php_prod = prod_compose[prod_compose.index('\n  php:'):prod_compose.index('\n  nginx:')]
    chk('docker-compose.prod.yml construye la imagen PHP de producción (Dockerfile.prod: sin Xdebug, git ni Composer)', 'dockerfile: docker/php/Dockerfile.prod' in php_prod)
    chk('docker-compose.prod.yml fija XDEBUG_MODE=off en el servicio php', re.search(r'XDEBUG_MODE:\s*"?off"?', php_prod) is not None)
    chk('el Dockerfile solo instala Xdebug si INSTALL_XDEBUG=1', 'if [ "$INSTALL_XDEBUG" = "1" ]; then install-php-extensions xdebug' in dockerfile and 'opcache xdebug' not in dockerfile)
    prod_df = '\n'.join(l for l in (Path(DIR) / 'docker' / 'php' / 'Dockerfile.prod').read_text(encoding='utf-8').splitlines() if not l.lstrip().startswith('#'))
    chk('B1: la imagen de producción no instala Xdebug, git, unzip ni Composer', not re.search(r'xdebug|apt-get install|composer:2 /usr/bin/composer', prod_df, re.I))
    chk('B1: el servicio php de producción es de solo lectura y no monta el código con escritura', 'read_only: true' in php_prod and './src:/var/www/html' not in php_prod.replace('./src/storage:/var/www/html/storage', ''))
    # C1 (resuelto): la seed que corre en producción no crea el administrador de la clave pública.
    seed = (Path(DIR) / 'database' / '02-seed.sql').read_text(encoding='utf-8')
    chk('la seed que también corre en producción no crea usuarios ni contiene la clave pública del admin (C1)', 'INTO usuarios' not in seed and 'Admin#2026dev' not in seed and '$2y$' not in seed)
    chk('docker-compose.prod.yml no pasa APP_ENV al servicio db (04-dev-admin.sh no crea el admin de desarrollo)', 'APP_ENV' not in prod_compose[prod_compose.index('\n  db:'):])
    entorno = en_php('printenv APP_ENV')
    if entorno != 'local':
        c2, r = iniciar('admin@lucas.local', 'Admin#2026dev')
        chk('el administrador de la seed (clave pública) NO puede entrar fuera de local', r.status != 302, f'APP_ENV={entorno}')
    else:
        print('  (login del admin de la seed omitido: APP_ENV=local, existe a propósito en desarrollo)')


# ═════════════════════════════════════════ main ═════════════════════════════════════════


def main() -> int:
    if '--limpiar' in sys.argv:
        limpiar()
        print('Datos de pruebas de ataque anteriores borrados.')
        return 0
    try:
        urllib.request.urlopen(BASE + '/login.php', timeout=5)
    except Exception:
        print(f'No responde {BASE}: ¿está en marcha docker compose up?')
        return 2
    limpiar()
    ea, eb, ed, ee, ef, ev, eh = (f'ataque_{RUN}_{x}@correo.com' for x in ('a', 'b', 'd', 'e', 'f', 'v', 'h'))
    t0 = time.time()
    try:
        A, B, E, F = (registrar(x) for x in (ea, eb, ee, ef))
        registrar(ed)
        mysql('DELETE FROM intentos_acceso')
        pruebas_idor(A, B, ea, eb)
        pruebas_privilegios(B, eb, ea)
        pruebas_carreras(A, ea)
        pruebas_entradas(A, ea)
        pruebas_archivos(A, ea)
        pruebas_importacion(A, B, ea, eb)
        pruebas_espacios(E, F, B, ee, ef)
        pruebas_sesion(ea)
        pruebas_correo(ev)
        pruebas_dos_pasos(eh)
        pruebas_acceso(ed)
        pruebas_recuperacion(ed)
        pruebas_exposicion()
    finally:
        limpiar()
    print(f'\nResultado: {ok} correctas, {fallos} fallidas, {conocidos} hallazgos conocidos pendientes, {resueltos} resueltos ({time.time() - t0:.0f} s)')
    if conocidos:
        print('Los «CONOCIDO» están descritos en docs/AUDITORIA.md con su solución.')
    return 1 if fallos else 0


if __name__ == '__main__':
    sys.exit(main())
