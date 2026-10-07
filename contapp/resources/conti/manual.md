## 1. Conceptos generales

### 1.1 Licencias y compañías
- No hay registro público: el equipo de CONTAPP emite cada **licencia** con un código. Quien la activa crea su compañía y queda como **Superusuario**, el dueño de la licencia.
- La pantalla de activación pide:
  - el «Código de licencia»;
  - los datos de la compañía: «Razón social», «Nombre comercial» y «Cédula jurídica» (opcional);
  - los datos de la persona: nombre, correo y contraseña. Si ya tiene cuenta, elige «Ya tengo una cuenta».
- **Una cuenta puede ser dueña de una sola licencia.** Una licencia puede tener varias compañías, hasta el máximo de su categoría. Cada categoría define cuántas empresas, administradores y usuarios admite, y su duración.
- **Agregar compañías:** en **Administración → Agregar compañía** (solo el Superusuario) se completa «Razón social», «Nombre comercial» y «Cédula jurídica» (opcional). Si ya se llegó al máximo, aparece «Ya alcanzaste el máximo de compañías de tu licencia»; para más cupo hay que contactar al equipo de CONTAPP.
- **Activar una licencia propia:** una persona que es Administrador o Usuario en la licencia de otro puede activar la suya en **Mi cuenta → Activar una licencia**. Esa opción solo aparece mientras la cuenta no sea dueña de una licencia. Si la cuenta la creó otra persona, primero hay que confirmar el correo con «Enviarme el enlace».
- Una persona puede tener acceso a **varias compañías**, propias o de otras licencias por invitación. Su **rol es por compañía**.

### 1.2 Cambiar de compañía
- Se cambia con el **selector de compañía** de la barra superior. Aparece una ventana de transición que confirma «Ahora estás en…». Si algo falla, la ventana ofrece reintentar, volver a la compañía anterior o cerrar la sesión.
- Junto al nombre, el selector marca el estado de la licencia de cada compañía.

### 1.3 Estados de la licencia
Se ven en el pie de página y en el selector de compañía:
- **Vigente**: normal.
- **Por vencer**: faltan 30 días o menos. Conviene renovar con el equipo de CONTAPP.
- **Vencida**: se entra en **modo de gracia**. Se puede consultar y exportar, pero no crear ni modificar nada. El mensaje es: «La licencia de tu compañía está vencida. Podés consultar y exportar información, pero no crear ni modificar registros hasta renovarla.»
  - En modo de gracia sí se puede: editar los datos personales en Mi cuenta, cambiar de compañía, cerrar sesión, aceptar invitaciones y usar el panel de comentarios.
  - Se renueva con el equipo de CONTAPP.
- **Suspendida** o **Revocada**: no se puede entrar a esa compañía. Aparece deshabilitada en el selector, con un mensaje que explica el motivo. Hay que contactar al equipo de CONTAPP.

### 1.4 Roles
- **Superusuario** (dueño de la licencia): tiene acceso total a sus compañías. Es el único que puede:
  - invitar Administradores;
  - convertir un Usuario en Administrador y viceversa;
  - gestionar Administradores;
  - cambiar el logo;
  - agregar compañías;
  - editar la razón social, el nombre comercial y la cédula jurídica de la compañía;
  - reabrir períodos cerrados.
- **Administrador**: invita **Usuarios** y les da permisos, **solo hasta el nivel que él mismo tiene**. Puede suspender, reactivar y desactivar Usuarios, y cambiar el tema en Apariencia. No gestiona a otros Administradores ni al Superusuario.
- **Usuario**: solo ve y hace lo que le dieron. Sin permisos, solo ve el **Panel**.
- El rol se muestra como insignia en la barra superior.

### 1.5 Permisos por pantalla
- Cada opción del menú lateral tiene su propio permiso:
  - **Sin acceso**;
  - **Lectura**: consultar y exportar;
  - **Lectura y escritura**: además crear, modificar y eliminar.

  Los **reportes** solo admiten Lectura.
- El menú muestra únicamente las opciones a las que la persona tiene acceso.
- «Comprobantes» (consultar facturas emitidas) y «Nueva factura» (emitir) son permisos **distintos**.
- **Administración** y **Mi cuenta** dependen del rol, no de un permiso.
- Si alguien no ve una opción o recibe un mensaje de que no tiene permiso, tiene que pedirle el acceso a un Administrador o al Superusuario.

### 1.6 Usuarios e invitaciones (Administración → Usuarios)
- **Invitar a una persona:**
  1. Tocá «Crear nuevo».
  2. Escribí el «Correo» y elegí el «Rol».
  3. Marcá los permisos de cada opción del menú.
  4. Tocá «Enviar invitación».
- La persona recibe un correo con un enlace que **vence en 7 días**:
  - Si **no tiene cuenta**, al aceptar elige su nombre y su contraseña: quien invita no tiene que inventarle una.
  - Si **ya tiene cuenta**, la compañía se suma a las que ya ve, con su misma contraseña.
  - Si al abrir el enlace hay otra sesión abierta, aparece «Cerrar sesión y continuar».
  - **Hasta que acepte, no entra.**
- **Invitaciones pendientes:**
  - «Reenviar» manda un enlace nuevo, y el anterior deja de servir.
  - «Cancelar invitación» invalida el enlace.
  - Una vencida se reenvía.
- **«La invitación ya no es válida»**: el enlace venció, se canceló, se reenvió (y la persona abrió el viejo) o ya se aceptó. La solución es pedir que la reenvíen.
- **Cupos:** las invitaciones pendientes también cuentan contra el máximo de administradores o usuarios de la licencia. Si no hay cupo, se puede cancelar una invitación pendiente, desactivar a alguien, o pedir más cupo al equipo de CONTAPP.
- **Permisos de alguien ya activo:** botón «Permisos» → «Editar permisos» → «Guardar».
- **Cambiar el rol** con «Hacer Administrador» o «Hacer Usuario» (solo el Superusuario).
- **Suspender**: la persona no entra hasta que la reactiven, y sus permisos quedan guardados.
- **Reactivar**: recupera el acceso con los mismos permisos.
- **Desactivar**: pierde el acceso de forma permanente. No se borra su historial (asientos, auditoría).
- En los tres casos se le avisa por correo.

### 1.7 Apariencia (Administración → Apariencia)
- La ven el Superusuario y los Administradores.
- **Tema de la compañía**: hay 10 temas (Marino, Grafito, Esmeralda, Borgoña, Petróleo, Índigo, Cobalto, Terracota, Salvia y Ónix). Al elegir uno se ve una vista previa. Se aplica con «Guardar tema» y se deshace con «Descartar vista previa».
- **Logo**: solo el Superusuario puede subirlo o quitarlo con «Quitar logo». Sale en los reportes, y es de cada compañía.
- El **modo claro u oscuro** es personal: se cambia con el botón de sol o luna de la barra superior.

### 1.8 Mi cuenta → Mis datos
- **Foto de perfil**: se ve junto al nombre en las listas de usuarios. Se quita con «Quitar foto».
- **Nombre y apellidos**: es como te ven en todas las compañías.
- **Correo**: para cambiarlo, CONTAPP manda un enlace al correo nuevo. El cambio se hace al abrir ese enlace, y mientras tanto se sigue entrando con el actual.
- **Contraseña**: se pide la actual, la nueva y la repetición. Al cambiarla se cierran las sesiones abiertas en otros dispositivos.
- **Política de contraseña**: al menos 8 caracteres, con 4 letras, 4 números, una mayúscula, una minúscula y un carácter especial. Si falta algo, el mensaje dice exactamente qué: «A la contraseña le falta tener al menos: …».
- **Compañías**: muestra el rol en cada una. El rol no se cambia desde acá, porque lo asigna quien administra la compañía.
- **Datos de la compañía**: el Superusuario los cambia con «Editar datos» (razón social, nombre comercial y cédula jurídica). Salen en el encabezado de los reportes y en los comprobantes que se emitan **desde ese momento**; los ya emitidos no cambian.
- **Olvidé mi contraseña**: en la pantalla de inicio de sesión, entrá a la recuperación de contraseña, escribí el «Correo» y tocá «Enviar enlace». El enlace del correo lleva a «Nueva contraseña».

### 1.9 Barra superior, menú y Panel
- La barra superior tiene:
  - el selector de compañía;
  - el modo claro u oscuro;
  - la insignia del rol;
  - el botón **Comentarios y noticias** (un punto avisa que hay noticias nuevas);
  - el botón «Salir».
- Si la licencia tiene Conti, el asistente, su botón es el redondo con un robot, en la esquina inferior derecha.
- El menú lateral se puede colapsar. Está organizado en secciones: Contabilidad, Centros de costo y cambiario, Inventario, Facturación, Planillas, Socios de negocio, Bancos, Impuestos, Administración y Mi cuenta. Dentro de cada sección, las opciones se agrupan en Operación, Reportes, Catálogos y Configuración. **No hay un menú general de reportes:** cada reporte vive en el módulo al que pertenece.
- El **Panel** es la página de inicio y muestra los documentos contabilizados recientes.

### 1.10 Comentarios y noticias
- **Comentarios**: para proponer mejoras o reportar algo. Se escribe un texto y se pueden adjuntar imágenes (JPG o PNG de hasta 5 MB). Se puede votar a favor o en contra, y comentar; lo más votado queda arriba.
- **Lo publicado lo ven todas las cuentas de CONTAPP**, no solo tu compañía: no hay que poner datos sensibles.
- Cuando el equipo de CONTAPP marca una publicación como solucionada o la elimina, su autor recibe un correo.
- **Noticias**: las novedades de CONTAPP que publica el equipo.

### 1.11 Comportamientos comunes en todas las pantallas
- «Crear nuevo» abre el formulario. Tocar una fila abre su **ficha**, con las acciones disponibles (Editar, Eliminar, Ver…).
- Muchas listas y reportes tienen «Exportar XLSX» y «Exportar PDF», o «Imprimir».
- **«Ver movimientos»** (en cuentas, socios y centros de costo) abre el **mayor auxiliar**, un panel lateral con:
  - saldo inicial y final;
  - movimientos con saldo acumulado;
  - filtro de fechas con atajos «Todo», «Este mes» y «Este año»;
  - exportación a Excel.

  Solo cuenta lo contabilizado.
- **Los códigos no se cambian** después de crear el registro: artículos, grupos, almacenes, ubicaciones, unidades, centros de costo, tipos de documento, lotes. Si está mal, se crea otro y se inactiva el anterior.
- **Lo que ya tiene movimientos no se elimina**: se inactiva.
- **Lo contabilizado no se edita ni se borra**: se **anula**, y la anulación genera un asiento de reversión. Los registros con historial (el kardex, las anotaciones de un empleado) son de solo agregar.

### 1.12 Monedas y tipo de cambio
- Cada compañía tiene una **moneda local** (normalmente colones, CRC), una **moneda extranjera** (normalmente dólares, USD) y una **moneda de sistema**, que siempre es USD.
- Cada asiento guarda sus montos en las tres monedas.
- Para convertir, CONTAPP usa el **último tipo de cambio registrado en o antes de la fecha de contabilización**. Sin tipo de cambio cargado, no se puede contabilizar (ver la sección 3.1).

### 1.13 Conti, el asistente
- El botón redondo del robot, en la esquina inferior derecha, se abre en dos al pasar el mouse por encima (o al tocarlo en el teléfono): **«Chat»** y **«Escanear»**. El panel del chat queda a la derecha y la conversación sigue al pasar de una pantalla a otra. «Nueva conversación» (la flecha circular) empieza de cero.
- Hay una conversación por compañía: al cambiar de compañía, Conti habla de la nueva.
- Conti ve solo lo que la persona puede ver según sus permisos, y nunca correos, teléfonos ni datos personales.
- **Lo que Conti puede preparar para guardar**, si la persona tiene Lectura y escritura en esa pantalla:
  - un asiento contable (preliminar o contabilizado);
  - crear o actualizar un socio de negocio;
  - aplicar un cobro o un pago a una partida abierta;
  - registrar un tipo de cambio;
  - crear un centro de costo o una cuenta contable;
  - cambiar precios de una lista de precios;
  - crear una orden de compra;
  - anotar en la bitácora de un trabajador;
  - registrar un movimiento de planilla (horas extra, bonos, rebajos) en un período abierto;
  - registrar vacaciones (disfrute, pago o ajuste).
- **Para registrar o editar, Conti muestra un formulario en el chat**, con lo que ya sabe precargado. Se completa ahí mismo; en los campos de código (cuentas, socios, artículos…) aparecen sugerencias al escribir. En un asiento, abajo de las líneas se ve si cuadran los débitos y los créditos.
- **Los campos marcados «Sugerido»** los completa CONTAPP según cómo se viene trabajando en la compañía: el código que sigue al último, la cuenta de control que usan los demás clientes, el tipo de documento de siempre, la última tasa de cambio… Debajo de cada uno dice por qué. Se pueden cambiar como cualquier otro campo.
- **Nada se guarda sin confirmar.** Al enviar el formulario (o cuando Conti ya tenía todos los datos), se abre sola una ventana encima de la pantalla en que se está, con el resumen:
  - «Confirmar y guardar» lo guarda, con el usuario de la persona y las mismas validaciones que la pantalla correspondiente; la pantalla de atrás se actualiza.
  - «Corregir» lo devuelve al chat como formulario para cambiar lo que haga falta.
  - «Descartar» no guarda nada.
  En el chat queda una tarjeta para volver a abrir la ventana. Vence a los 30 minutos.
- **Dictar en vez de escribir:** el botón del micrófono, al lado de «Enviar», convierte la voz en texto (en Chrome, Edge y Safari; Firefox no lo tiene).
  - La primera vez, el navegador pide permiso para usar el micrófono; si se negó, se vuelve a dar desde el candado de la barra de direcciones.
  - El texto aparece en el campo mientras se habla y queda ahí para revisarlo o corregirlo: se envía con «Enviar» o Enter, nunca solo.
  - Se detiene solo al dejar de hablar, o tocando el micrófono de nuevo.
- **Escanear un documento** («Escanear», en el botón de Conti): Conti lee una foto del documento y llena el formulario.
  - Primero se elige qué registrar: una factura de gasto o compra (asiento), una cotización o pedido a un proveedor (orden de compra), los datos de un cliente o proveedor nuevo, o un comprobante de pago (aplicar el pago). Solo aparece lo que la persona puede registrar con sus permisos.
  - En el teléfono se abre la cámara ahí mismo. En la computadora aparece un código QR: se escanea con la cámara del teléfono, se toma la foto (hasta 3 páginas) y se envía. El formulario aparece solo en la computadora. El código sirve una sola vez y por 10 minutos, y en el teléfono no hace falta iniciar sesión. También se puede subir una imagen que ya esté en la computadora.
  - Lo leído del documento va marcado «Del documento». Lo que Conti no encontró en CONTAPP (un proveedor o un artículo que no está registrado) queda vacío, con una nota arriba: hay que buscarlo o crearlo primero.
  - Revisá cada dato y corregí lo que haga falta antes de enviarlo; después viene la ventana de confirmación de siempre.
  - Las fotos no se guardan en CONTAPP: se leen y se descartan. Leer un documento gasta créditos, como un mensaje.
  - En el teléfono, el documento completo, de frente y con buena luz se lee mejor.
- **Cuando Conti necesita que elijas** (un período, entre dos cuentas parecidas…), te muestra opciones para tocar, de una o varias, y siempre podés escribir otra respuesta.
- Para cualquier otra cosa (facturar, mover inventario, cerrar períodos, calcular planillas…), Conti explica cómo hacerlo en la pantalla.
- Con la licencia vencida, Conti responde y consulta, pero no prepara nada para guardar.
- **Conti es parte de la licencia.** El equipo de CONTAPP lo activa o lo desactiva para cada licencia, y le puede poner límites de uso en créditos: por día y por semana para toda la licencia (todas sus compañías y personas juntas), y por persona al día. Si la licencia no lo tiene, el botón Conti no aparece.
- **El cupo de la licencia es compartido:** lo usan todas las personas de la licencia, el Superusuario incluido. Si alguien lo gasta, nadie más puede usar Conti hasta que se renueve.
- **El Superusuario lo reparte.** Al invitar a alguien o en **Administración → Usuarios → Editar permisos**, en la sección «Conti, el asistente», decide para cada Administrador o Usuario:
  - si puede usar Conti;
  - su límite por día y por semana, en créditos, que no puede pasar los de la licencia;
  - qué modelos puede elegir.
  Esa sección solo la ve el Superusuario, y la ficha de cada persona muestra lo que gastó hoy y en la semana. Si alguien no ve el botón de Conti, es que el Superusuario no le dio acceso.
- **Al llegar a un límite**, Conti avisa y deja de responder hasta que se renueve: el diario se renueva a las 00:00 y el semanal el lunes a las 00:00 (hora de Costa Rica). Cuando falta poco para el límite, Conti avisa en el chat. Para más cupo, hay que contactar al equipo de CONTAPP.
- Cada mensaje consume según cuánto trabajo le cuesta a Conti responder: una pregunta simple gasta poco; una que corre varios reportes, más.
- **Modelo y consumo** (el ícono del medidor, arriba en el chat):
  - Cada persona elige con qué modelo de OpenAI le responde Conti. Los más capaces (GPT-4.1, GPT-5) razonan o analizan mejor, pero gastan más créditos por mensaje; GPT-4.1 nano es el más económico. La elección es personal: no cambia la de las demás personas.
  - Ahí mismo se ve lo que gastó la persona hoy, esta semana y este mes (tokens, créditos y mensajes), y sus límites con cuánto lleva usado.
- Conti no guarda el texto de la conversación en la base de datos: la recuerda unas horas para seguir el hilo, y «Nueva conversación» la empieza de cero.

---

## 2. Contabilidad

### 2.1 Catálogo de cuentas (Contabilidad → Catálogo de cuentas)
- El catálogo se organiza en **8 clases**, cada una en su propio cajón: Activos, Pasivos, Patrimonio, Ingresos, Costo de Ventas, Gastos, Otros Ingresos y Otros Gastos. La **naturaleza** (débito o crédito) se deduce de la clase y no se digita.
- Campos de una cuenta:
  - **Código**, **Tipo (clase)**, **Nombre** y **Nombre en inglés** (opcional).
  - **Moneda**: Local, Extranjera o Ambas.
  - **Clasificación IVA**: Ninguno, Ventas, Compras, IVA General, IVA Devengado o IVA Soportado.
  - **Indicador de impuesto vinculado** (opcional).
  - **Cuenta hoja (acepta movimientos)**: solo las cuentas hoja reciben asientos. Las cuentas mayores suman lo de sus hojas.
  - **Exige socio de negocio (CxC/CxP)**: cada línea contra esta cuenta tiene que llevar un cliente o proveedor.
  - **Cuenta monetaria (elegible para bancos)**: solo una cuenta monetaria se puede vincular a una cuenta bancaria.
  - **Exige norma de reparto en cada línea**: para cuentas de costo o gasto que se distribuyen entre centros de costo.
  - **Activa**.
- La ficha de una cuenta ofrece «Editar», «Eliminar», «Ver movimientos» y «Reconciliar». «Reconciliar» aparece en las cuentas hoja que no exigen socio.
- Una cuenta **solo se elimina si nunca tuvo movimientos ni subcuentas**. Si tiene, se inactiva.
- **Carga masiva por Excel:**
  - «Descargar plantilla» baja un Excel con las cuentas actuales. Se completa y se sube con «Importar XLSX».
  - Es **todo o nada**: si una fila tiene errores, no se carga nada y se muestran todos los errores para corregirlos de una vez.
  - Las cuentas con un código existente se actualizan y las nuevas se crean. Nunca se borra nada.

### 2.2 Reconciliación interna de cuentas
Se abre desde la ficha de la cuenta, con «Reconciliar». Sirve para emparejar movimientos de una misma cuenta que se compensan entre sí y suman cero, como las cuentas puente o transitorias. Las líneas con socio de negocio no entran: esas se concilian en las partidas abiertas del socio.
- Pestañas «No reconciliados» y «Reconciliados».
- **Reconciliar seleccionados**: los movimientos marcados tienen que sumar cero. En cada uno se puede reconciliar un monto parcial.
- **Deshacer**: revierte una reconciliación.
- **Traspaso a otra cuenta (ARR)**: contabiliza un asiento con el tipo reservado ARR entre esta cuenta y otra cuenta o socio, para después reconciliar.
- **Reclasificar a otra cuenta**: corrige un movimiento registrado en la cuenta equivocada. Contabiliza el traspaso y reconcilia en un solo paso.

### 2.3 Tipos de documento (Contabilidad → Tipos de documento)
- Campos de un tipo de documento:
  - **Código (3 letras)**: inalterable.
  - **Nombre**, **Módulo de origen** y **Moneda del documento**.
  - **Cuenta débito por defecto** y **Cuenta crédito por defecto** (opcionales).
  - **Consecutivo interno inicial**: solo al crear. Sirve para continuar la numeración de un sistema anterior. Después nadie lo puede cambiar.
  - **Control de socio de negocio en líneas**: define qué exige cada línea con socio. Puede ser nada, una fecha de vencimiento (la línea **abre una partida** por cobrar o por pagar), una **aplicación a una partida existente**, o cualquiera de las dos.
  - **Genera asiento contable**.
  - **Exige clave numérica electrónica de Hacienda (50 dígitos)**: cada línea pide la clave del comprobante electrónico. Una misma clave no se puede repetir en la compañía.
  - **Estado**.
- **Numeración:**
  - El **consecutivo interno** se asigna solo y siempre.
  - Además, se pueden crear **series manuales**, varias por tipo, cada una con su rango. Por ejemplo, talonarios físicos de recibos.
  - Campos de una serie: nombre, «Encargado (opcional)» (a quién se le entregó el talonario), «Número inicial» (no se cambia después), «Número final» y «Activa».
  - Cuando una serie se agota, se crea otra.
- **Tipos reservados del sistema**: **APE** (saldos iniciales), **ARR** (traspaso de reconciliación) y **ACC** (cierre anual). No se eligen a mano en un asiento.

### 2.4 Registros (asientos contables) (Contabilidad → Registros)

**La lista «Asientos»:**
- Filtros por «N.º de documento», «Tipo de documento» y fechas «Desde» y «Hasta».
- Botones «Exportar XLSX», «Exportar PDF», «Descargar plantilla», «Importar XLSX» y «Crear nuevo».
- **Estados**:
  - **Preliminar**: un borrador editable. No tiene número ni período, y no afecta saldos.
  - **Contabilizado**.
  - **Anulado**.
- Las acciones de la ficha dependen del estado:
  - En un preliminar: «Editar» y «Eliminar» (el borrado es definitivo).
  - En un contabilizado: «Ver», «Anular» y «Duplicar».

**El encabezado del formulario:**
- **Tipo de documento**.
- **Fecha de documento**: la de la factura o comprobante. Es informativa.
- **Fecha de contabilización**: define el **período**, el **tipo de cambio** y las **vigencias** (de centros de costo, normas de reparto e indicadores de impuesto).
- **Serie** (opcional): si se elige una, el asiento lleva además el número de esa serie.
- **Fecha de vencimiento** (opcional).
- **Tipo de cambio**: si se deja vacío, se usa el automático, que aparece como sugerencia. Si se escribe un valor, rige **solo para este asiento**. El factor a la moneda de sistema siempre es automático.
- **Descripción**.

**Las líneas:**
- Cada línea se registra en modo «Cuenta» o «Socio». En modo Socio se elige el cliente o proveedor, y la cuenta de control del socio se pone sola.
- Columnas: «Moneda», «Débito», «Crédito» y «Descripción».
- Según la cuenta y el tipo de documento, una línea puede pedir además:
  - una **Norma de reparto**;
  - un indicador de **IVA**: al elegirlo, se agrega sola la línea del impuesto;
  - el «Doc. de referencia» y su «Fecha del doc.»;
  - un «Vencimiento» (marcando «Abre partida (vencimiento)»), o una partida existente elegida en «Aplicar a partida»;
  - la **Clave numérica electrónica**.
- Cada línea se puede duplicar o eliminar.

**Los botones:**
- **«Guardar como preliminar»**: no exige que cuadre ni consume número. Sirve para dejar un asiento a medias o para que otra persona lo revise.
- **«Contabilizar»**: exige que cuadre (débitos = créditos). Asigna el número, el período y los montos en las tres monedas. Un preliminar se contabiliza con el mismo botón al editarlo.
- **«Buscar y cargar un documento existente…»**: un preliminar se abre para editarlo; uno contabilizado se carga como copia nueva.

**Asientos recurrentes («Programable»):**
1. Marcá «Programable».
2. Elegí la «Frecuencia» («Cada cantidad de días» o «Cada cantidad de meses (períodos)») y cada cuánto.
3. Si querés, poné una «Fecha límite de vencimiento».
4. Tocá «Programar».

La primera corrida es la fecha de contabilización. Cada corrida genera un **preliminar** para revisar: nunca se contabiliza nada solo.

**Anular un asiento contabilizado:**
- Se crea un **asiento de reversión** espejo; el original no se modifica ni se borra.
- No se puede anular directamente si alguna línea ya tiene cobros o pagos aplicados a su partida, o si forma parte de una reconciliación interna. Primero hay que deshacer eso.

**Duplicar:** abre un asiento nuevo precargado con las mismas líneas.

**La ficha completa del asiento («Ver»):**
- Muestra las líneas, los centros de costo, las monedas y el tipo de cambio aplicado.
- «Ver mayor auxiliar».
- **Corregir el vencimiento** de una línea. Solo cambia el vencimiento de esa línea y de su partida, no el asiento.
- **«Vincular socio»** a una línea que no lo tiene. Solo se ofrecen socios cuya cuenta de control sea exactamente la de esa línea.
- Una vista para «Imprimir».

**Importar XLSX:** la plantilla trae un asiento completo por archivo (encabezado y líneas) y **siempre queda como preliminar**, para revisarlo antes de contabilizar.

### 2.5 Registros programados (Contabilidad → Registros programados)
- Lista las plantillas de asientos recurrentes: «Tipo», «Fecha», «Descripción», «Próxima corrida» y «Estado».
- **«Procesar vencidas ahora»** genera de inmediato los preliminares que ya tocaban.
- **«Cancelar programación»** detiene una plantilla.
- Los asientos generados quedan como **preliminares** en Registros, para revisarlos y contabilizarlos.

### 2.6 Saldos iniciales (Contabilidad → Saldos iniciales)
Sirve para arrancar en CONTAPP una compañía que ya operaba en otro sistema.
1. Fijá la **«Fecha del asiento de apertura»** **antes** de subir el archivo, porque el archivo se contabiliza apenas se elige. Normalmente es el primer día del período en que se empieza a usar CONTAPP.
2. Si querés, escribí una «Descripción».
3. Tocá **«Descargar plantilla»**. Trae una fila por cada cuenta y cada socio de negocio.
4. Completá los saldos y tocá **«Subir XLSX y contabilizar»**.

Reglas:
- Débitos y créditos tienen que cuadrar exactamente. Si hay cualquier error, **no se contabiliza nada** y se listan todos los errores.
- Los saldos de clientes y proveedores quedan como **partidas pendientes**, listas para aplicarles cobros o pagos.
- Se usa el tipo reservado APE.

### 2.7 Cierre de períodos (Contabilidad → Cierre de períodos)
- Un **año fiscal** tiene 12 períodos mensuales. «Crear próximo año fiscal» genera el siguiente.
- **«Cerrar período»**: después ya no se puede registrar con fecha de contabilización en ese período. **No se puede cerrar si hay asientos preliminares con fecha dentro del período**: primero hay que contabilizarlos o eliminarlos.
- **«Reabrir período»**: **solo el Superusuario**.
- **«Cerrar año»**:
  1. Elegí la cuenta de **Utilidades acumuladas**.
  2. Todos los períodos del año, **menos el último**, tienen que estar cerrados.
  3. CONTAPP contabiliza el asiento de cierre (tipo ACC) en el último período, que lleva las cuentas de resultados contra utilidades acumuladas, y cierra ese período y el año.
- Si al contabilizar aparece «No existe un período fiscal configurado para la fecha…», falta crear el año fiscal.

### 2.8 Reportes contables (Contabilidad, grupo Reportes)
- **Balance de comprobación**: «Desde», «Hasta» y «Ocultar cuentas sin movimiento». Muestra el saldo inicial, los débitos, los créditos y el saldo final por cuenta, con «Ver movimientos».
- **Estado de resultados**: «Desde» y «Hasta».
- **Balance general**: «Al».
- **Comparativo entre periodos**: dos períodos lado a lado, con la variación y el %. El balance usa el saldo acumulado al cierre de cada período y el estado de resultados, la actividad de cada uno.
- **Comparativo de empresas**: las compañías a las que la persona tiene acceso, una al lado de la otra: Activo, Pasivo, Patrimonio, Ventas y Utilidad neta. **Nunca se suman**, porque pueden estar en monedas distintas.
- **Registro por tipo de documento**: los documentos contabilizados de cada tipo.
- **Reportes guardados**: guarda una combinación de parámetros con un «Nombre». Si se marca «Compartido con mi compañía», la ven los demás. Se usa con «Ejecutar».
- **Exportar catálogos**: un solo XLSX con una hoja por cada catálogo elegido, con la opción «Incluir inactivos / no vigentes».

---

## 3. Centros de costo y cambiario

### 3.1 Tipos de cambio (Centros de costo y cambiario → Tipos de cambio)
- Muestra el historial reciente: «Fecha», «Tipo», «Tasa» y «Origen» (BCCR o manual).
- **Sincronizar con el BCCR**: elegí la «Fecha a sincronizar» y tocá «Sincronizar con BCCR».
- **Carga o corrección manual**: «Fecha», «Tipo» (Referencia, Compra o Venta), «Tasa (₡ por 1 USD)» y «Bloquear».
  - Bloquear evita que la sincronización automática del BCCR la sobrescriba.
  - Si ya existe esa fecha y ese tipo, se **actualiza** en vez de duplicarse.
- Error típico: «No hay tipo de cambio registrado … en o antes de [fecha]». Se resuelve sincronizando o cargando el tipo de cambio de esa fecha o de una anterior.

### 3.2 Diferencial cambiario
Revalúa los saldos en moneda extranjera de clientes, proveedores y cuentas al tipo de cambio de una fecha de corte, y contabiliza la ganancia o pérdida.
- **Diferencial: ejecutar**:
  1. Completá los criterios: «Fecha de corte», «Tipo de documento», «Cuenta de ganancia cambiaria» y «Cuenta de pérdida cambiaria», y filtrá los socios y las cuentas contables.
  2. Revisá la tabla: «Saldo (ME)», «LC histórico», «LC revaluado» y «Diferencia». Podés usar «Marcar todos», «Ninguno», «Aceptar todo» y «Rechazar todo», o «Volver a criterios».
  3. Tocá «Crear».

  Solo se ajusta la moneda local: el saldo en moneda extranjera no cambia.
- **Diferencial: historial**: cada corrida con su «Corte», «Documento», «Tipo de cambio» y «Ejecutado por».

### 3.3 Centros de costo (Centros de costo y cambiario → Centros de costo)
- Es un catálogo de destinos de costo o gasto, por ejemplo Zona Norte, Ventas o Producción. No tiene niveles ni jerarquía.
- Campos: «Código» (no se cambia después de creado), «Nombre», «Vigente desde», «Vigente hasta (opcional)» y «Activo».
- **Los montos llegan a los centros a través de las normas de reparto**: en el asiento no se elige un centro directamente.
- Un centro tiene que estar activo y vigente en la fecha de contabilización para recibir montos.
- «Ver movimientos» abre su mayor auxiliar.
- Un centro con movimientos no se elimina: se inactiva.

### 3.4 Normas de reparto
- Campos: «Código», «Nombre», «Vigente desde», «Vigente hasta» y «Activa».
- La norma lista los centros de costo con su porcentaje, y **tienen que sumar 100 %**.
- Cuando una cuenta «Exige norma de reparto», cada línea contra esa cuenta pide una norma, y el monto se reparte entre los centros según los porcentajes.

### 3.5 Reportes
- **Auxiliar por centro de costo**: «Desde» y «Hasta», con las cuentas, los débitos y los créditos por centro. Para el detalle cronológico de un centro, usá «Ver movimientos».
- **Reporte de normas de reparto**: compara el % definido con el % real que resultó de los asientos. Una fila se resalta si la diferencia supera un punto porcentual.

---

## 4. Socios de negocio

### 4.1 Ficha del socio (Socios de negocio → Socios de negocio)
- Campos de la ficha:
  - **Código (x-xxx)**.
  - **Tipo**: Cliente, Proveedor o Ambos.
  - **Nombre**, **Cédula**, **Correo electrónico**, **Teléfono**, **Nombre del encargado** y **Código de actividad económica**.
  - **Cliente/proveedor desde**: por defecto la fecha de hoy, pero se puede poner una fecha anterior al migrar.
  - **Estado** (Activo o Inactivo), **Categoría** (opcional) y **Centro de costo** (opcional).
  - **Cuenta contable**: es la **cuenta de control**, normalmente Cuentas por Cobrar o por Pagar. Tiene que ser una cuenta hoja.
  - **Moneda**.
  - **Lista de precios**: una propia, o «Predeterminada de la compañía».
  - **Límite de crédito** y **Plazo de pago (días)**.
- Desde la ficha: «Editar», «Movimientos y saldo» (el mayor auxiliar del socio) y «Partidas abiertas».

### 4.2 Partidas abiertas
Son los documentos pendientes de cobro o de pago: facturas, saldos iniciales, notas. Cada uno muestra «Documento», «Vencimiento», «Saldo» y «Estado».
- **«Aplicar pago»**: elegí la «Cuenta de pago» (banco o caja), el «Monto» y la «Fecha», y tocá «Confirmar pago». Contabiliza el cobro o pago y lo aplica a la partida.
- **«Reconciliar entre sí»**: cruza partidas del mismo socio; por ejemplo, una factura contra una nota de crédito.
- **«Corregir vencimiento»**: cambia solo el vencimiento de la partida. El asiento que la originó no se modifica. Afecta a la antigüedad de saldos y a la proyección de cobros y pagos.

### 4.3 Categorías de socios
- Campos: «Código», «Nombre» y «Lista de precios que heredan sus socios» (o ninguna, y usan la predeterminada). Por ejemplo, Mayorista, Minorista o Gobierno.
- Se le asigna una a cada socio desde su ficha.

### 4.4 Antigüedad de saldos
- Parámetros: «Al»; los «Socios» (clientes y proveedores, solo clientes o solo proveedores); y los «Cortes» (estándar 30/60/90 o personalizados).

---

## 5. Bancos

- **Cuentas bancarias** (Bancos → Cuentas bancarias): «Banco», «Número de cuenta», «Cuenta contable» y «Moneda». La cuenta contable tiene que ser monetaria y distinta para cada cuenta bancaria.
- **Conciliaciones bancarias**:
  1. Elegí la cuenta. Se ve la última conciliación y su estado.
  2. Abrí una nueva con la «Fecha de corte» y el «Saldo según el banco». El **saldo de libros se calcula solo**, desde la cuenta contable hasta la fecha de corte.
  3. En la conciliación, marcá los movimientos que ya aparecen en el estado del banco.
  4. Tocá «Cerrar conciliación».

  Una conciliación cerrada se puede «Reabrir», y una abierta, «Eliminar».
- **Reporte de conciliaciones**: por cuenta, año y mes. Muestra el saldo del banco ajustado, el saldo de libros ajustado, si «Cuadra», y los movimientos.
- **Proyección de cobros y pagos**: lo que se va a cobrar y pagar según los vencimientos de las partidas abiertas. Parámetros: «Al» y los cortes (estándar 15/30/60/90 o personalizados).

---

## 6. Inventario

### 6.1 Principios
- **Costo promedio ponderado móvil, global por artículo**: es el mismo costo en todos los almacenes, lotes y ubicaciones.
- **Cada movimiento genera su asiento contable** en el mismo momento.
- El **kardex** es de solo agregar: una corrección se hace con un movimiento nuevo.
- Las existencias y el costo **no se digitan** en la ficha del artículo: las mantiene el motor de movimientos. **Las existencias iniciales entran con un «Ajuste de entrada de mercancía».**
- **Determinación de cuentas** (qué cuenta de inventario, costo de ventas, ajuste o ingresos usa cada artículo): gana lo más específico, en este orden: **artículo → grupo → almacén → compañía**. Lo que se deja vacío se hereda del nivel siguiente.

### 6.2 Catálogos
- **Artículos**:
  - Datos: «Código», «Nombre», «Grupo», «Unidad de medida», «Código de barras», «Indicador de impuesto» y «Estado».
  - «Lleva inventario»: desmarcado, es un **servicio**, que se compra o vende sin kardex ni costo. «Se compra» y «Se vende».
  - «Maneja lotes»: cada movimiento exige un número de lote. «Maneja números de serie»: una serie por unidad.
  - «Mínimo de existencia» y «Máximo»: alimentan la sugerencia de compra. En cero, no hay control de reorden.
  - Datos fiscales: «Código CAByS» (13 dígitos), «Unidad de medida de Hacienda» y «Tarifa de IVA de Hacienda».
  - Cuentas propias (opcionales).
  - Desde el artículo se llega a su **kardex**, sus **lotes**, sus **series** (que nacen con la entrada de mercancía; solo se editan la garantía y las notas) y sus **niveles por almacén** (un mínimo y un máximo distintos por almacén).
- **Grupos de artículos**: clasifican y, además, pueden definir las cuentas de todo el grupo.
- **Almacenes**:
  - «Código», «Nombre», «Dirección», «Estado» y «Almacén por defecto».
  - «Maneja ubicaciones»: cada movimiento pide una ubicación, por ejemplo A-01-03.
  - Cuentas propias (opcionales).
  - Las ubicaciones son logísticas: mover algo entre ubicaciones no genera asiento.
- **Unidades de medida**: «Código», «Nombre» y «Decimales». Se usa 0 para lo indivisible y 2 o 3 para kilos, litros o metros.
- **Listas de precios**:
  - «Código», «Nombre», «Moneda», «Vigente desde» y «Vigente hasta» (sirve para cargar en diciembre los precios de enero).
  - «Los precios ya incluyen el IVA».
  - «Predeterminada (la usan los clientes sin lista propia)».
  - «Estado».
  - En la pantalla de precios, **dejar un precio vacío quita el artículo de la lista**, y **poner cero significa regalarlo**. El margen contra el costo solo se muestra si la lista está en moneda local. Una lista no se convierte de moneda.
- **¿Qué lista de precios se aplica a un cliente?** La de la ficha del cliente; si no tiene, la de su categoría; si no, la predeterminada de la compañía. Si el artículo **no está** en la lista que aplica, **no hay precio sugerido**: CONTAPP no busca en otra lista.
- **Determinación de cuentas** (Inventario → Configuración): reglas con «Alcance», «Aplica a», «Categoría contable», «Cuenta contable» y «Norma de reparto». La norma es obligatoria si la cuenta la exige. El alcance y la categoría de una regla no se cambian.

### 6.3 Movimientos (Inventario → Movimientos)
- **Operaciones manuales**:
  - **Ajuste de entrada de mercancía**: existencias iniciales, sobrantes, donaciones.
  - **Entrada por compra (pendiente de facturar)**: la deuda queda en la **cuenta puente GR/IR** hasta que llega la factura del proveedor.
  - **Salida de mercancía**: consumo, mermas, faltantes.
  - **Ajuste por conteo físico**: en este caso, **la cantidad es lo contado**, no la diferencia. CONTAPP calcula el ajuste.
- Encabezado: «Operación», «Tipo de documento», «Fecha del documento», «Fecha de contabilización», «Proveedor», «Orden de compra (opcional)» y «Descripción».
- **Importaciones**: en una entrada por compra se marca «Esta entrada es una importación» y se completan los datos del DUA: «Número de DUA», «Aduana», «Fecha del DUA», «Documento de transporte» y «País de origen». Solo las importaciones admiten después rubros de nacionalización.
- Líneas: «Artículo», «Almacén», «Ubicación», «Lote» o serie, cantidad y «Costo unitario». Se registra con «Contabilizar movimiento».
- En el detalle de un movimiento:
  - **«Anular entrada»**: solo una entrada por compra que todavía no se facturó.
  - **«Copiar a»**: crea la factura de proveedor o la nota de crédito a partir de la entrada.

### 6.4 Compras
- **Órdenes de compra**:
  - Encabezado: «Proveedor», «Fecha de la orden» y «Fecha esperada».
  - Líneas: «Artículo», «Almacén destino», «Cantidad» y «Costo pactado». El costo pactado es informativo: el costo real lo fija la entrada.
  - La orden **no genera asiento**: solo declara qué viene en camino.
  - «Cancelar orden» solo si todavía no recibió nada. Si ya recibió una parte, se usa **«Cerrar con saldo»**.
- **Sugerencia de compra**: propone cantidades según el mínimo y el máximo y lo disponible. Las cantidades se pueden editar, y con «Crear orden de compra» se arma la orden.
- **Facturas de proveedor**:
  1. Elegí la entrada por compra pendiente y tocá «Facturar».
  2. Completá «Tipo de documento», «Vencimiento», «Fecha de la factura», «Fecha de contabilización», «Neto facturado (sin IVA)», «Cuenta de IVA» y «Monto de IVA».
  3. Tocá «Contabilizar factura».

  La factura **liquida la cuenta puente GR/IR** y abre la partida por pagar del proveedor.
- **Nota de crédito de proveedor** (devolución): indicá cuánto «Devolver» de lo «Recibido», el «Precio acreditado» y el «IVA devuelto». La mercancía sale del inventario. Si lo acreditado difiere del costo de entrada, la diferencia va a resultados.
- **Costos de importación**: aplica un costo (flete, arancel…) directamente sobre una entrada ya recibida. Campos: «Tipo de documento», «Quién lo cobra», «Monto del costo», «Vencimiento», «Fecha del documento», «Fecha de contabilización» y «Concepto».
- **Rubros de nacionalización**: es el proceso en dos fases para importaciones.
  1. **Registrar el rubro**: «Proveedor del servicio» (naviera, agencia aduanal, almacén fiscal), «Rubro», «Tipo de documento», «Monto», «Fecha del documento» y «Vencimiento». Se contabiliza contra la **cuenta transitoria de costos por asignar** y abre la deuda con ese proveedor.
  2. **Proceso de costeo**: elegí la importación y los rubros que se le cargan, y tocá «Asignar al costo». El costo se reparte entre las líneas según su valor. Un rubro se puede repartir entre varias importaciones.
- En los dos casos, la parte que corresponde a mercancía **ya vendida** no se puede capitalizar y se lleva a resultados automáticamente.

### 6.5 Existencias
- **Traslados**:
  - «Tipo de documento», «Fecha de contabilización» y «Motivo».
  - Líneas: «Artículo», «Desde», «Hacia», «Cantidad» y «Ubicación». Se registra con «Trasladar».
  - **No genera asiento** si los dos almacenes comparten la cuenta de inventario.
- **Tomas físicas**:
  1. Abrí la toma con «Fecha de corte», «Almacén», «Familia de artículos», «Tipo de documento del ajuste» y «Conteo a ciegas» (recomendado: la hoja impresa oculta la existencia del sistema).
  2. Al abrirse, la toma **congela** la existencia teórica.
  3. Imprimí la hoja, contá, y anotá lo contado con «Guardar conteo».
  4. Tocá «Cerrar y ajustar» para generar el ajuste por las diferencias.

  Se descarta con «Cancelar toma». Mientras una toma está abierta, ese almacén no admite otra.
- **Deterioro (NIC 2)**: es el avalúo de **valor neto realizable** (VNR).
  1. Completá «Corte al» y la descripción.
  2. En cada artículo, indicá el «VNR unitario» y el «Motivo».
  3. Tocá «Contabilizar avalúo».

  Un efecto negativo significa que se revirtió una estimación anterior porque el VNR se recuperó.

### 6.6 Producción
- **Listas de materiales** (recetas):
  - «Código», «Nombre», «Producto que fabrica», «Unidades que rinde la receta completa», «Predeterminada para este producto» y «Estado».
  - Componentes: «Cantidad por lote» (por receta completa, no por unidad), «Merma %», cómo «Se emite» y «Almacén».
  - La merma es lo que se pierde en el proceso: si de cada 100 se pierden 5, hay que emitir 105.
  - La receta no tiene costo: el costo sale del promedio de cada componente al emitir.
- **Órdenes de fabricación**:
  1. Creá la orden: «Producto a fabricar», «Receta» (o sin receta, digitando la emisión), «Almacén de ingreso», «Cantidad planificada» y «Fecha».
  2. **«Emitir materia prima»**: los componentes salen al costo promedio y se acumulan en **Producto en Proceso**. Las líneas se precargan desde la receta, escaladas y con la merma.
  3. **«Recibir producto»**: con la «Cantidad producida». El costo sale de lo acumulado en proceso y no se digita.
  4. **«Cerrar orden»**: liquida la desviación que quede.

### 6.7 Lotes y series
- **Lotes**:
  - Cada lote tiene su «Número de lote», «Vencimiento», «Estado» (Activo o Retenido) y «Notas».
  - Son trazabilidad, no valoración: dos lotes del mismo artículo salen al mismo costo promedio.
  - El número no se cambia.
  - Se puede ver la **trazabilidad de un lote**: dónde está y qué movimientos tuvo.
- **Series**: nacen con la entrada de mercancía. Se puede editar la garantía y las notas, o «Dar de baja».

### 6.8 Reportes de inventario
- **Reportes de inventario** reúne los siguientes. Todos se pueden exportar a Excel o PDF, o imprimir:
  - **Lista de artículos**: detecta artículos a medio configurar, por ejemplo sin CAByS o sin mínimo.
  - **Existencias y compromisos**: lo que hay, lo apartado por pedidos, lo que viene en camino y lo libre.
  - **Partidas abiertas**: pedidos y órdenes de compra con saldo pendiente.
  - **Movimientos por artículo**: cada entrada y salida, con su documento y su asiento.
  - **Listas de precios y margen**: precios contra el costo promedio.
  - **Rentabilidad por artículo**: lo facturado contra el costo descargado.
  - **Rotación y cobertura**: cuántas veces rota cada artículo y para cuántos días alcanza.
  - **Análisis ABC**: qué pocos artículos concentran la mayor parte del dinero.
- **Existencias valorizadas**: el valor a una fecha, reconstruido desde el kardex. Tiene que coincidir con las cuentas de inventario a esa fecha.
- **Antigüedad de inventario**: tramos de días por artículo y almacén.
- **Lotes por vencer**: lotes con saldo que vencen dentro del horizonte de días elegido. Sirve como insumo para decidir un deterioro.

---

## 7. Facturación

### 7.1 Parámetros de facturación (Facturación → Parámetros de facturación)
Se configuran antes de facturar:
- **Actividades económicas**: «Código (6 dígitos)», «Nombre de la actividad», «Cuenta de ingresos» y «Actividad por defecto». La actividad con la que se factura decide a qué cuenta de ingresos va la venta.
- **Cuentas de IVA por tarifa**: «Tarifa», «Cuenta de IVA por pagar» e «Indicador de impuesto». El indicador es opcional pero recomendado: sin él, esas ventas no aparecen en el Reporte de IVA.
- **Medios de pago**: «Medio de pago» y «Cuenta». Es la cuenta que se debita en una venta de contado según cómo se cobró.

### 7.2 Nueva factura (Facturación → Nueva factura)

**Tipos de comprobante:**
- 01 Factura electrónica
- 02 Nota de débito electrónica
- 03 Nota de crédito electrónica
- 04 Tiquete electrónico
- 08 Factura electrónica de compra
- 09 Factura electrónica de exportación
- 10 Recibo electrónico de pago

**El encabezado:**
- «Tipo de comprobante» y «Tipo de documento (ERP)».
- «Sucursal» y «Terminal».
- «Actividad económica del emisor».
- «Cliente» (o «Consumidor final») y «Actividad del receptor».
- «Moneda» y «Tipo de cambio».
- **«Condición de venta»**: si es a crédito, se indica el «Plazo del crédito (días)» y la venta **abre una partida por cobrar** con ese vencimiento.
- «Fecha de emisión» y «Fecha de contabilización».
- «Observaciones».

**Las líneas:**
- «Artículo», «Descripción», «Bodega», «Cant.» y «Precio». El precio se precarga desde la lista de precios del cliente.
- Las acciones de la línea permiten indicar:
  - «CAByS», «Unidad de medida», «Tarifa de IVA» y «Ubicación en la bodega»;
  - un **descuento**: «Código», «Monto» y «Naturaleza del descuento»;
  - otro **impuesto**;
  - una **exoneración**: «Tipo de documento de exoneración», «Número de documento», «Artículo», «Inciso», «Institución» y «% exonerado»;
  - el número de VIN o serie, en vehículos.

**Referencia:** las notas de crédito y de débito exigen indicar qué documento corrigen o anulan, con el «Tipo de documento», la «Clave o número», la «Razón» y la «Justificación».

**Precio distinto al de la lista:**
- Si una línea se aparta del precio de lista, la factura **necesita que la libere un Administrador o el Superusuario**, en ese momento, con su «Usuario que autoriza» y su «Contraseña», más un «Motivo» opcional. El botón pasa a ser «Autorizar y emitir».
- Si el precio ya se autorizó en la orden de pedido, esa firma vale también para la factura.
- Todo queda registrado en **Cambios de precio autorizados**.

**Hacienda (firma y envío):**
- Si la compañía no tiene certificado de firma configurado, aparece un aviso: el comprobante **se emite y se registra en el ERP**, y su **XML queda disponible para descargar**, pero **no se puede firmar ni enviar a Hacienda** hasta cargar la llave criptográfica.
- Si alguien pregunta por el envío a Hacienda, explicá esto y sugerí contactar al equipo de CONTAPP para la configuración de la firma.

### 7.3 Comprobantes (Facturación → Comprobantes)
- La lista de lo emitido: «Consecutivo», «Fecha», «Cliente», «Total» y «Estado». Al tocar uno se ve el detalle.
- Desde un comprobante, **«Copiar a → Nota de crédito»** prepara la nota de crédito.
- **Nota de crédito (03)**: devuelve la mercancía al inventario **al costo original** y cancela la partida por cobrar.
- Ver los comprobantes requiere el permiso «Comprobantes», y emitirlos, el permiso «Nueva factura».

### 7.4 Órdenes de pedido (Facturación → Órdenes de pedido)
- Encabezado: «Cliente», «Fecha del pedido», «Fecha de entrega» y «Descripción».
- Líneas: «Artículo», «Bodega», «Cantidad» y «Precio pactado».
- El pedido **no genera asiento**: **aparta** la mercancía para ese cliente.
- Solo se puede apartar lo **disponible**: la existencia menos lo que otros pedidos ya comprometieron.
- Para facturarlo, desde el pedido se usa **«Copiar a → Factura de venta»**. La reserva se libera al facturar lo entregado o con «Cancelar pedido». Cancelar no toca lo ya facturado.

### 7.5 Cambios de precio autorizados (Facturación → Reportes)
- Cada línea que se apartó del precio de lista: «Fecha», «Documento», «Artículo», «Diferencia» y «Lo autorizó».
- Se filtra por fechas y por quién autorizó.
- Sirve para detectar listas de precios mal puestas: el mismo descuento repetido muchas veces es señal de eso.

---

## 8. Planillas (Costa Rica)

### 8.1 Configuración inicial (en este orden)
1. **Parámetros de planilla** → **«Cargar plantilla de Costa Rica»**. Trae los componentes de carga social de la CCSS, la escala del impuesto al salario, los créditos familiares, las provisiones (aguinaldo, vacaciones, cesantía) y los conceptos de uso común. **Las tasas de la plantilla hay que verificarlas contra el decreto vigente** antes de la primera planilla real.
2. **Determinación de cuentas** de planilla, desde Parámetros: todas las cuentas en un solo lugar.
   - Las cuentas obligatorias son la de **Gasto de salarios** y la de **Planilla por pagar**. Además se configura el impuesto al salario por pagar, la cuenta de cada carga social (gasto y pasivo), las provisiones y los conceptos.
   - El tipo de documento del asiento.
3. **Departamentos y puestos**:
   - Los departamentos tienen un centro de costo por defecto.
   - Los puestos llevan el **código de ocupación CCSS**. El catálogo de la Caja no viene cargado.
   - El salario mínimo y máximo de referencia solo sirve para avisar.
4. **Empleados**.

**Otros parámetros:**
- «Días de vacaciones por mes trabajado».
- «Tope de deducciones (% del disponible)».
- El promedio para pagar vacaciones en una liquidación.
- Cómo se lleva la quincena al mes para el impuesto.
- Sobre qué se aplica la escala.

**El impuesto al salario:**
- La escala es **mensual y progresiva**.
- La base es el bruto gravable **menos las cargas obreras**.
- Los **créditos familiares (hijos y cónyuge) se restan del impuesto**, no de la base.

**Conceptos de planilla:**
- Cada concepto es un **Ingreso** o una **Deducción**.
- Se calcula de una de tres formas: «Se digita el monto», «Por horas × factor» o «Porcentaje del salario».
- Tiene **tres banderas**:
  - «Forma salario para cargas sociales»;
  - «Está sujeto al impuesto al salario»;
  - «Entra a la base de aguinaldo, vacaciones y cesantía».

  Marcarlas mal produce una planilla que no cuadra con la Caja.

### 8.2 Empleados (Planillas → Empleados)
- **Identificación:** «Código», «Tipo de identificación», «Número», nombre y apellidos, fecha de nacimiento, correo y teléfono.
- **Asignación:** «Fecha de ingreso», «Departamento», «Puesto», «Centro de costo» y «Cuenta de gasto» (opcional).
- **Condiciones laborales:**
  - «Tipo de contrato».
  - «Jornada» (Diurna de 8 h, Mixta de 7 h o Nocturna de 6 h) y «Horas semanales».
  - «Tipo de salario»: mensual, quincenal, semanal, diario o por hora.
  - «Salario base».
  - **«Divisor del día»**, para quien cobra por semana: 6 si la semana paga los días laborados, o 7 si la semana incluye el descanso.
- **Pago:** «Forma de pago», «Banco» y «Cuenta IBAN».
- **Impuesto y cargas:**
  - «Hijos con crédito» y «Crédito por cónyuge».
  - «No aplicar impuesto al salario».
  - «Asegurado CCSS» y «Pensionado».
  - «No cotiza NINGUNA carga social por esta planilla».
- **Estado:** «Estado», «Fecha de salida», «Motivo» y «Notas».

El **valor del día** es la base de vacaciones, aguinaldo, incapacidades y liquidaciones. La **hora** sale de dividir el día entre las horas de la jornada, y es la base de las horas extra.

La ficha del empleado muestra también:
- las **anotaciones**: hechos, observaciones y llamadas de atención. No se editan; una corrección se anota encima;
- el saldo de vacaciones;
- las deducciones;
- el historial de cambios, con su vigencia y quién los aprobó.

### 8.3 Períodos de planilla (Planillas → Períodos de planilla)
- Para crear un período: «Año», «Frecuencia», «Número», «Desde», «Hasta», **«Fecha de pago»** y «Nombre». La fecha de pago manda para el asiento y para las tasas vigentes, y puede caer en el mes siguiente.
- **Estados:** **abierta → calculada → aprobada → contabilizada**. Solo se recalcula en los dos primeros.

**Flujo:**
1. **Cargar los movimientos del período**: horas extra, bonos y rebajos puntuales. Se cargan uno por uno con «Agregar movimiento» («Trabajador», «Concepto», «Horas», «Monto» y «Referencia»), o en masa con «Descargar plantilla» y «Subir archivo».
   - La carga masiva **reemplaza** los movimientos del período.
   - Los trabajadores se emparejan por su código.
   - Si el archivo tiene errores, no se carga nada.
   - El salario base sale de la ficha y **las cargas sociales las calcula el sistema**: no se digitan.
2. **«Calcular planilla»** (o «Recalcular»).
3. **«Aprobar»**.
4. **«Contabilizar»**, con la «Fecha de contabilización». El asiento carga al gasto el **salario bruto** (no el neto), abona las retenciones a sus pasivos y deja el neto en **«planilla por pagar»**. **El banco no se toca**: el pago es otro asiento.
5. **«Archivo de pago»** del banco y **«Enviar comprobantes»** por correo. También se puede usar «Exportar XLSX».

**Correcciones:**
- **«Reabrir»**: una planilla aprobada vuelve a calculada, y se limpia la aprobación.
- **«Anular»**: una planilla contabilizada se anula con un asiento de reversión, eligiendo su fecha.

**Boletas (comprobantes de pago):**
- Muestran la base y la tasa de cada rebajo, congeladas al calcular.
- Muestran también las cargas que paga la empresa.
- Se pueden «Enviar por correo» o «Imprimir».

### 8.4 Rubros fijos (Planillas → Rubros fijos)
- Son montos que se repiten cada período: una bonificación, un salario en especie, un rebajo acordado.
- Campos: «Trabajador», «Rubro», «Horas por período» o «Monto por período», «Vigente desde», «Vigente hasta» (vacío = indefinido), «Referencia» y «Estado».
- **Si en un período se digita el mismo rubro, lo digitado reemplaza al fijo; no se suma.** El fijo vuelve solo el período siguiente.

### 8.5 Deducciones y préstamos (Planillas → Deducciones y préstamos)
- Sirve para adelantos, préstamos, cuotas solidaristas y embargos.
- Campos:
  - «Trabajador», «Tipo», **«Prioridad»** (el número menor se rebaja primero), «Descripción», «Referencia» y «Concepto de planilla».
  - «Desde» y «Hasta».
  - «Monto otorgado» y «Saldo actual».
  - «Cómo se calcula la cuota»: «Monto fijo», «Porcentaje del bruto del período» o «Usar la del concepto».
  - «Cuenta contable».
  - «Estado»: Activa, Suspendida, Cancelada o Anulada.
- Se aplican solas al calcular, por prioridad. **Nunca dejan el neto en negativo**: lo que no cabe queda como saldo para el período siguiente.
- Algunos tipos se rebajan indefinidamente. Para terminarlos, se les pone una fecha final o se suspenden.

### 8.6 Vacaciones (Planillas → Vacaciones)
- El saldo es la **suma de movimientos**. Cada planilla calculada **acredita los días automáticamente**, en proporción a lo trabajado. Una acreditación automática se corrige recalculando la planilla, no borrándola.
- Movimientos manuales: «Disfrute», «Pago en efectivo» y «Ajuste».
- **«Movimiento masivo»**: aplica el mismo movimiento a varios trabajadores (cierres, saldos iniciales, convenios).

### 8.7 Acciones de personal (Planillas → Acciones de personal)
- Registran aumentos, cambios de puesto, jornada o centro de costo, salidas, amonestaciones y suspensiones.
- Campos: «Trabajador», «Tipo de acción», «Rige desde» y los datos nuevos.
- **Estados:** **borrador → aprobada → aplicada**. Se registra con «Registrar en borrador» y avanza con «Aprobar» y «Aplicar». También se puede «Anular».
- **Quien registra la acción no puede aprobarla.**
- Una acción aprobada con vigencia futura queda **en espera**, y se aplica desde su fecha.

### 8.8 Liquidaciones laborales (Planillas → Liquidaciones laborales)
1. Elegí «Trabajador», «Fecha de salida», **«Causal»** y los «Hechos de la salida». Las causales son: Renuncia, Despido con responsabilidad del trabajador, Despido sin justa causa, Vencimiento del plazo, Mutuo acuerdo o Fallecimiento.
2. Tocá «Calcular liquidación».

**Cómo se calcula:**
- **Aguinaldo y vacaciones pendientes siempre se pagan.** El **preaviso** y la **cesantía** dependen de la causal.
- En una renuncia, el preaviso lo debe el trabajador: no se le paga, y rebajárselo requiere su autorización expresa.
- La cesantía sigue la tabla del art. 29 del Código de Trabajo, con un **tope de 8 años**.
- Los promedios salen de lo **realmente devengado** (art. 30), incluyendo horas extra y comisiones.
- **Las cargas sociales solo se aplican a las vacaciones y a los salarios pendientes.** La cesantía, el preaviso y el aguinaldo están exentos.

**Ajustes manuales:**
- «Salarios pendientes»: no se calculan solos, para evitar un doble pago.
- «Indemnización» y «Deducción».

**Estados y acciones:**
- «Recalcular», «Aprobar», «Devolver a borrador» y «Contabilizar».
- «Anular» contabiliza una reversión, devuelve los días de vacaciones y deja al trabajador activo otra vez.

### 8.9 Reportes de planilla (Planillas → Reportes de planilla)
Todos se ven en pantalla con filtros, se puede elegir las columnas, y salen a Excel, PDF o la impresora.
- **Personal:** **Empleados**: la ficha completa, para detectar datos incompletos antes de calcular.
- **Planilla del período:**
  - **Planilla íntegra por período**: del bruto al neto y al costo total.
  - **Detalle de boletas por período**: de dónde salió cada rebajo o ingreso.
- **Cargas e impuestos:**
  - **Planilla de la CCSS**: las bases y cuotas por componente, para conciliar con la Caja.
  - **Retenciones de impuesto al salario**.
- **Deducciones y préstamos:**
  - **Deducciones por tipo de rubro**: cuánto girar a cada destino.
  - **Control de préstamos y ahorros**.
- **Vacaciones:**
  - **Vacaciones: saldo colectivo**.
  - **Vacaciones: tarjeta individual**.
- **Terminación:**
  - **Liquidaciones laborales**.
  - **Acciones de personal**.
- **Costos y pasivo laboral:**
  - **Pasivo laboral acumulado**: las provisiones de aguinaldo, vacaciones y cesantía.
  - **Costo patronal por centro de costo**.

Además, desde el período de planilla se obtienen los **Comprobantes de pago** y el **Archivo de pago del banco**.

---

## 9. Impuestos

- **Indicadores de impuesto** (Impuestos → Indicadores de impuesto):
  - Los **indicadores nacionales** (IVA 13 %, 4 %, 2 %, 1 %, etc.) son comunes a todas las compañías y los administra el equipo de CONTAPP. Aparecen como «Indicador nacional».
  - Cada compañía puede crear **indicadores propios**, de un tipo de impuesto existente o de un tipo nuevo. Campos: «Código», «Porcentaje», «Nombre», «Da derecho a crédito fiscal», «Detalle del crédito fiscal», «Vigente desde» y «Vigente hasta».
  - Un indicador **ya usado en asientos no se elimina ni cambia su porcentaje**: solo se le cierra la vigencia. Para un porcentaje nuevo se crea otro indicador.
- **Reporte de IVA**: «Desde» y «Hasta», por fecha de contabilización. Muestra la «Clasificación», la «Base gravable» y el «Impuesto».
  - Se calcula desde los movimientos que tienen indicador de impuesto.
  - Una corrección posterior a un indicador **no altera** un período ya declarado.
  - Para que las ventas aparezcan, las cuentas de IVA de los parámetros de facturación tienen que tener su indicador.

---

## 10. Preguntas frecuentes

- **¿Cómo empiezo con una compañía nueva?**
  1. Revisá o cargá el catálogo de cuentas.
  2. Creá el año fiscal en Cierre de períodos.
  3. Cargá los tipos de cambio.
  4. Creá los tipos de documento que hagan falta.
  5. Registrá los socios de negocio.
  6. Cargá los saldos iniciales.
  7. Si usás inventario, configurá almacenes, grupos, artículos, determinación de cuentas y existencias iniciales (con un ajuste de entrada).
  8. Si facturás, completá los parámetros de facturación.
  9. Si llevás planilla, cargá la plantilla de Costa Rica, las cuentas, los departamentos, los puestos y los empleados.
- **Me equivoqué en un asiento contabilizado.** No se edita: anulalo (se crea la reversión) y registralo de nuevo, o usá «Duplicar» para partir del original. Si solo está mal el vencimiento o falta vincular el socio, eso se corrige desde la ficha del asiento.
- **¿Por qué no puedo eliminar una cuenta, un artículo o un centro de costo?** Porque ya tiene movimientos. Inactivalo.
- **¿Por qué no veo un menú?** Por los permisos: pedíselo a un Administrador o al Superusuario.
- **¿Puedo cambiar un código?** No. Creá uno nuevo e inactivá el anterior.
- **¿Cómo cargo las existencias iniciales?** En Inventario → Movimientos, con la operación «Ajuste de entrada de mercancía», con cantidades y costos.
- **¿Por qué una factura me pide usuario y contraseña de otra persona?** Porque el precio se apartó de la lista de precios. Lo tiene que liberar un Administrador o el Superusuario.
- **¿Por qué no se precarga el precio?** Porque el artículo no está en la lista de precios que aplica a ese cliente, o la lista no está vigente o activa.
- **El tipo de cambio de un asiento salió distinto al que esperaba.** CONTAPP usa el último registrado en o antes de la fecha de contabilización. Se puede escribir uno manual en el asiento, que rige solo para ese asiento.
- **¿Cómo registro un cobro de un cliente?** En Socios de negocio, abrí la ficha del cliente → «Partidas abiertas» → «Aplicar pago».
- **¿Dónde veo cuánto me deben o debo por antigüedad?** En Socios de negocio → Antigüedad de saldos. Para ver lo que viene, Bancos → Proyección de cobros y pagos.
- **¿Cómo creo un asiento que se repite cada mes?** Marcá «Programable» en el formulario del asiento.
- **¿Qué pasa si la licencia vence?** Se puede consultar y exportar, pero no crear ni modificar hasta renovar con el equipo de CONTAPP.

---

## 11. Mensajes de error frecuentes y cómo resolverlos

- **«El asiento no cuadra en moneda …: débitos … vs créditos …»**: los débitos y los créditos no suman lo mismo. Revisá los montos. Para dejarlo a medias, usá «Guardar como preliminar».
- **«No existe un período fiscal configurado para la fecha …»**: falta crear el año fiscal (Cierre de períodos → «Crear próximo año fiscal»).
- **«El período fiscal #… (… a …) está 'closed'; no se puede registrar.»** (o 'blocked'): la fecha de contabilización cae en un período cerrado o bloqueado. En Cierre de períodos los estados se ven como Abierto, Bloqueado y Cerrado. Usá una fecha de un período abierto, o pedile al Superusuario que lo reabra si corresponde.
- **«No hay tipo de cambio registrado para la moneda … en o antes de …»**: cargá o sincronizá el tipo de cambio (Centros de costo y cambiario → Tipos de cambio), o escribí un tipo de cambio manual en el asiento.
- **«La cuenta … no acepta movimientos: no es cuenta hoja.»**: elegí una subcuenta hoja. Las cuentas mayores no reciben asientos.
- **«La cuenta … exige socio de negocio en cada línea.»**: registrá la línea en modo «Socio», eligiendo el cliente o proveedor.
- **«La cuenta … exige centro de costo o norma de reparto en cada línea.»**: elegí una «Norma de reparto» en esa línea.
- **«La norma de reparto … está inactiva o fuera de su vigencia…»**, o lo mismo con un centro de costo: revisá la vigencia y el estado de la norma o de sus centros a la fecha de contabilización.
- **«La norma de reparto … no tiene centros de costo configurados que sumen 100%.»**: completá los porcentajes de la norma.
- **«El tipo de documento … exige "…" en cada línea con socio de negocio…»**: ese tipo de documento exige que cada línea con socio abra una partida (con vencimiento) o se aplique a una partida existente.
- **«La serie "…" agotó su rango…»** o **«…está inactiva»**: creá una serie nueva en Tipos de documento o elegí otra.
- **«El indicador … no está vigente para la fecha …»**: usá un indicador vigente a la fecha de contabilización.
- **«El monto de impuesto de la línea … no corresponde al indicador…»**: el IVA no calza con la base y el porcentaje. Revisá la base.
- **«Solo se pueden anular asientos ya contabilizados…»**: un preliminar no se anula, se elimina.
- **«…ya tiene cobros/pagos aplicados a su partida…; no se puede anular directamente.»**: primero deshacé las aplicaciones de esa partida.
- **«…ya forma parte de una reconciliación interna; deshacé esa reconciliación primero.»**: andá a la reconciliación de esa cuenta y usá «Deshacer».
- **No se puede cerrar el período porque hay preliminares**: contabilizá o eliminá los asientos preliminares con fecha dentro del período.
- **«La licencia de tu compañía está vencida. Podés consultar y exportar información, pero no crear ni modificar registros hasta renovarla.»**: es el modo de gracia. Hay que renovar con el equipo de CONTAPP.
- **«La licencia de … está suspendida…»** o **«…fue revocada…»**: no se puede entrar a esa compañía. Hay que contactar al equipo de CONTAPP.
- **«Ya alcanzaste el máximo de compañías de tu licencia.»**: pedí más cupo al equipo de CONTAPP.
- **«A la contraseña le falta tener al menos: …»**: cumplí la política de contraseña (8 caracteres, 4 letras, 4 números, una mayúscula, una minúscula y un carácter especial).
- **La invitación «ya no es válida»**: pedí que te la reenvíen. Pudo haber vencido (dura 7 días), haberse cancelado, haberse reenviado, o ya estar aceptada.
- **Sin permiso o error 403 al abrir una pantalla**: falta el permiso de esa opción del menú, o es de solo lectura y se intentó modificar. Pedile el acceso a un Administrador o al Superusuario.
- **«Tu sesión venció»**: volvé a iniciar sesión.
- **Al vender: no hay suficiente disponible**: lo disponible es la existencia menos lo apartado por pedidos. Revisá Existencias y compromisos.
