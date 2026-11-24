# Requisitos

Contexto del producto, requisitos funcionales (RF) y no funcionales (RNF), y estado de implementación de cada módulo.

Prioridad: Alta (A), Media (M), Baja (B). Estado: ⬜ pendiente · 🟨 en curso · ✅ hecho.

## Contexto

### Idea
**Lucas** es una aplicación web que permite a una persona registrar sus ingresos y gastos, organizarlos por categorías y cuentas, fijar presupuestos mensuales y crear metas de ahorro, para saber en qué se va su dinero y cuánto le queda disponible.

### Problemática
Muchas personas jóvenes y trabajadores independientes manejan su dinero de memoria, en el cuaderno o en notas del celular. Esto genera problemas como:
- No saber en qué gastaron el dinero al final del mes.
- Gastar más de lo planeado en categorías como comida, transporte o entretenimiento, sin darse cuenta a tiempo.
- No lograr ahorrar porque no tienen una meta clara ni un seguimiento.
- Registros dispersos (efectivo, cuenta bancaria, billeteras digitales) que no permiten ver el saldo total.
- Las hojas de cálculo existentes exigen disciplina y conocimientos que no todos tienen.

### Propósito y objetivos
Ofrecer una herramienta sencilla, gratuita y accesible desde cualquier navegador que ayude a **tomar el control del dinero**: registrar movimientos en pocos pasos, ver resúmenes claros y recibir avisos cuando se acerca al límite de un presupuesto.

- Permitir el registro de ingresos y gastos por cuenta y categoría.
- Mostrar resúmenes mensuales y un saldo total actualizado.
- Permitir definir presupuestos por categoría y alertar al superarlos.
- Permitir crear metas de ahorro y llevar el avance de cada una.

### Público objetivo
Jóvenes y estudiantes que empiezan a manejar su dinero, trabajadores dependientes e independientes que quieren ordenar sus gastos, y familias pequeñas que llevan el presupuesto del hogar. No se requieren conocimientos financieros previos.

### Alcance
**Incluye:** registro de usuarios, cuentas, categorías, movimientos, presupuestos, metas de ahorro, reportes mensuales, exportación a CSV y administración de usuarios.
**No incluye (versiones futuras):** conexión automática con bancos, pagos, inversiones, aplicación móvil nativa y manejo de varias monedas con conversión.

## Requisitos funcionales

### Gestión de usuarios
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-01 | El sistema debe permitir registrar un usuario con nombre, correo y contraseña. | A |
| RF-02 | El sistema debe permitir iniciar y cerrar sesión. | A |
| RF-03 | El sistema debe permitir editar el perfil (nombre y moneda) y cambiar la contraseña. | M |
| RF-04 | El sistema debe permitir recuperar la contraseña mediante un enlace enviado al correo. | M |

### Cuentas
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-05 | El usuario podrá crear cuentas (efectivo, bancaria, tarjeta de crédito, ahorros) con un saldo inicial. | A |
| RF-06 | El usuario podrá consultar, editar y desactivar sus cuentas. | A |
| RF-07 | El sistema mostrará el saldo actual de cada cuenta y el saldo total. | A |

### Categorías
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-08 | El sistema ofrecerá categorías predeterminadas de ingresos y gastos (alimentación, transporte, salud, salario, etc.). | A |
| RF-09 | El usuario podrá crear, editar y eliminar sus propias categorías. | M |

### Movimientos (ingresos y gastos)
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-10 | El usuario podrá registrar un ingreso o un gasto indicando monto, fecha, cuenta, categoría y descripción. | A |
| RF-11 | El usuario podrá consultar el listado de movimientos con paginación. | A |
| RF-12 | El usuario podrá filtrar movimientos por fecha, cuenta, categoría y tipo. | A |
| RF-13 | El usuario podrá editar y eliminar movimientos, con confirmación previa. | A |
| RF-14 | El saldo de la cuenta se actualizará automáticamente al registrar, editar o eliminar un movimiento. | A |

### Presupuestos
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-15 | El usuario podrá definir un límite mensual de gasto por categoría. | A |
| RF-16 | El sistema mostrará cuánto se ha gastado frente al límite (barra de progreso). | A |
| RF-17 | El sistema avisará al usuario cuando alcance el 80 % y cuando supere el 100 % del presupuesto. | M |

### Metas de ahorro
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-18 | El usuario podrá crear una meta con nombre, monto objetivo y fecha límite. | M |
| RF-19 | El usuario podrá registrar aportes a una meta desde una cuenta. | M |
| RF-20 | El sistema mostrará el porcentaje de avance y el monto faltante de cada meta. | M |

### Reportes
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-21 | El sistema mostrará un panel con ingresos, gastos y balance del mes. | A |
| RF-22 | El sistema mostrará gráficos de gastos por categoría y de la evolución mensual. | M |
| RF-23 | El usuario podrá exportar sus movimientos a un archivo CSV. | B |

## Requisitos no funcionales

| Código | Categoría | Requerimiento |
|---|---|---|
| RNF-01 | Seguridad | Las contraseñas se almacenarán cifradas con `password_hash()` (bcrypt/Argon2); nunca en texto plano. |
| RNF-02 | Seguridad | Todas las consultas a la base de datos usarán consultas preparadas con PDO para evitar inyección SQL. |
| RNF-03 | Seguridad | Los datos mostrados se escaparán con `htmlspecialchars()` para evitar XSS, y los formularios tendrán token CSRF. |
| RNF-04 | Seguridad | Cada usuario solo podrá ver y modificar sus propios datos; se validará la propiedad en el servidor. |
| RNF-05 | Seguridad | Las credenciales de la base de datos estarán en un archivo `.env` fuera del repositorio. |
| RNF-06 | Seguridad | Las operaciones que modifican datos usarán POST, y la sesión expirará tras 30 minutos de inactividad. |
| RNF-07 | Seguridad | En producción la aplicación funcionará sobre HTTPS. |
| RNF-08 | Rendimiento | Las páginas principales deberán cargar en menos de 3 segundos con conexión de 4 Mbps. |
| RNF-09 | Rendimiento | El listado de movimientos se paginará (20 por página) y las columnas de búsqueda tendrán índices. |
| RNF-10 | Usabilidad | Registrar un movimiento no deberá requerir más de 3 clics desde el panel principal. |
| RNF-11 | Usabilidad | La interfaz será responsive (celular, tableta y computador) y usará español. |
| RNF-12 | Usabilidad | Los errores y confirmaciones se mostrarán con mensajes claros, sin detalles técnicos. |
| RNF-13 | Accesibilidad | Contraste suficiente de colores, etiquetas en todos los campos y uso con teclado. |
| RNF-14 | Disponibilidad | El sistema estará disponible al menos el 98 % del tiempo mensual. |
| RNF-15 | Confiabilidad | Se harán copias de seguridad automáticas de la base de datos cada semana. |
| RNF-16 | Integridad | Los montos se guardarán como `DECIMAL(12,2)` y se validarán positivos; se usarán transacciones SQL al actualizar saldos. |
| RNF-17 | Compatibilidad | Compatible con las últimas dos versiones de Chrome, Firefox, Edge y Safari. |
| RNF-18 | Mantenibilidad | Código organizado en MVC, con nombres claros y comentarios. |
| RNF-19 | Escalabilidad | Soportar al menos 500 usuarios simultáneos sin cambios de arquitectura. |
| RNF-20 | Privacidad | Cumplir la Ley 1581 de 2012 (protección de datos personales en Colombia): el usuario podrá eliminar su cuenta y sus datos. |

## Requisitos de registro y administración

### Registro y acceso
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-24 | Una vista pública de **registro** (`registro.php`) permite a cualquier persona crear su cuenta con nombre, correo y contraseña (RF-01). | A |
| RF-25 | El registro **siempre** crea usuarios con rol `usuario`. El rol nunca se lee del formulario: no se puede crear un administrador desde la web pública. | A |
| RF-26 | Al registrarse, el usuario recibe las categorías predeterminadas (RF-08, ya disponibles para todos) y entra directamente al panel. | M |
| RF-27 | Tras iniciar sesión, un `admin` va al dashboard de administración y un `usuario` a su panel. Un `usuario` que abra una URL de `admin/` recibe 403. | A |

### Dashboard de administración
| Código | Requerimiento | Prioridad |
|---|---|---|
| RF-28 | El dashboard muestra métricas globales: usuarios totales, activos, nuevos del mes, y totales de cuentas y movimientos registrados. | A |
| RF-29 | Muestra una gráfica de **registros por mes** (últimos 12 meses). | M |
| RF-30 | Lista los usuarios con búsqueda por nombre o correo y paginación (20 por página). | A |
| RF-31 | El administrador puede **activar/desactivar** a un usuario. Un usuario desactivado no puede iniciar sesión; sus datos se conservan. | A |
| RF-32 | El administrador puede **cambiar el rol** de un usuario (`usuario` ⇄ `admin`). | M |
| RF-33 | Un administrador no puede desactivarse ni quitarse el rol a sí mismo, ni dejar al sistema sin ningún administrador activo. | A |
| RF-34 | El administrador puede gestionar las **categorías predeterminadas** (las de `id_usuario IS NULL`). | M |

### No funcionales nuevos
| Código | Categoría | Requerimiento |
|---|---|---|
| RNF-21 | Privacidad | El dashboard de administración muestra **solo agregados** y datos de cuenta (nombre, correo, rol, estado, fecha de registro). Un administrador no ve los movimientos, saldos, presupuestos ni metas de ningún usuario (RNF-04, RNF-20). |
| RNF-22 | Seguridad | Toda página de `admin/` exige sesión **y** rol `admin`, comprobado en el servidor en cada petición (no basta con ocultar el menú). Los cambios de estado/rol son POST con CSRF. |
| RNF-23 | Seguridad | El administrador de la primera seed trae una clave de desarrollo conocida: debe cambiarse en cualquier entorno que no sea la máquina local (ver `docs/BASE-DE-DATOS.md`, «Seed»). |
| RNF-24 | Seguridad | El registro y el login limitan los intentos (por IP y por correo). El login responde igual si el correo existe o no; el registro, al no haber correo transaccional, sí informa que el correo ya está registrado (ver `docs/PLAN.md`, D2). |

## Estado de implementación

| Módulo | Requisitos | Fase | Estado |
|---|---|---|---|
| Esqueleto Docker/PHP | RNF-05, RNF-02 | 0 | ✅ |
| Registro, login, logout, sesión | RF-01, RF-02, RF-24 a RF-27, RNF-01, RNF-06, RNF-23, RNF-24 | 1 | ✅ |
| Dashboard de administración | RF-28 a RF-33, RNF-21, RNF-22 | 2 | ✅ |
| Cuentas | RF-05 a RF-07 | 3 | ⬜ |
| Categorías (incl. predeterminadas del admin) | RF-08, RF-09, RF-34 | 3 | ⬜ |
| Movimientos | RF-10 a RF-14 | 4 | ⬜ |
| Presupuestos | RF-15 a RF-17 | 5 | ⬜ |
| Metas de ahorro | RF-18 a RF-20 | 5 | ⬜ |
| Panel y reportes | RF-21 a RF-23 | 6 | ⬜ |
| Perfil y recuperar contraseña | RF-03, RF-04, RNF-20 | 7 | ⬜ |
| Verificación transversal | RNF-07, RNF-08, RNF-13 a RNF-15, RNF-17, RNF-19 | 7 | ⬜ |

## Referencias

- Elmasri, R. y Navathe, S. B. (2016). *Fundamentals of database systems* (7.ª ed.). Pearson.
- Congreso de la República de Colombia. (2012). *Ley estatutaria 1581 de 2012*, por la cual se dictan disposiciones generales para la protección de datos personales.
- OWASP Foundation. *SQL injection prevention cheat sheet*. <https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html>
- The PHP Group. *Password hashing*. Manual de PHP. <https://www.php.net/manual/es/book.password.php>
