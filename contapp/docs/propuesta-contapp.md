# CONTAPP — Propuesta de Sistema

**Estado:** Propuesta inicial para validación
**Fecha:** 2026-08-04
**Alcance:** Arquitectura, modelo de datos, RBAC, motor de documentos, contabilidad multimoneda, CxC/CxP, bancos, IVA (Costa Rica), diferencial cambiario, cierres, IA e interfaz.

Este documento desarrolla, dominio por dominio, el sistema descrito en el brief original, tomando como restricciones innegociables las ya fijadas en `CLAUDE.md`. Donde el brief dejaba una decisión abierta, se propone una opción concreta con su justificación (marcado como **Decisión propuesta**), para que quede registrada en `docs/decisiones.md` una vez aprobada.

---

## 1. Visión general de arquitectura

**Monolito modular** en Laravel 13 + MySQL 8 + Redis (colas/cache) + Docker, desplegable como SaaS multi-tenant de **base de datos compartida con `company_id` en cada tabla** (no schema-per-tenant).

**Decisión propuesta — estrategia multi-tenant:** una sola base de datos, con `company_id` como columna obligatoria y un *global scope* de Eloquent (`CompanyScope`) aplicado a todos los modelos transaccionales. Se descarta "una BD por empresa" porque: (a) el brief pide cambio de compañía *dentro de la misma sesión de la aplicación* sin relogin, lo cual es trivial con un scope y costoso con conexiones dinámicas; (b) simplifica migraciones, backups y el futuro reporting consolidado entre compañías del mismo grupo; (c) es el patrón que escala mejor en SaaS Laravel (equivalente a lo que hace Salesforce/QuickBooks Online). Aislamiento se refuerza con el scope + policies + auditoría, no con separación física.

```
app/Domains/
  Core/           Companies, Users, Roles, Permissions, DocumentTypes, AuditLog, AI
  Accounting/     ChartOfAccounts, JournalEntries, FiscalPeriods, Currencies, FxRevaluation, Closing
  BusinessPartners/  Partners, Categories, OpenItems, PaymentApplications
  Tax/            TaxTypes, TaxRates, TaxReports
  Banking/        BankAccounts, Reconciliation, BankStatementImport
```

Cada dominio expone **Services** (`PostJournalService`, `ApplyPaymentService`, `FxRevaluationService`, `PeriodCloseService`, `BankReconciliationService`...) que son el único punto de escritura contable. Controladores API/UI y jobs programados (BCCR, revaluación) llaman siempre a los services — nunca escriben `journal_details` directamente. Esto es lo que permite, más adelante, que un agente de IA "registre" una transacción sin saltarse ninguna regla de negocio: la IA solo arma el payload que el mismo service valida.

**Backend:** Laravel 13 API-first (Sanctum para sesión SPA + tokens para integraciones), Form Requests + Policies por acción, Pest para las reglas innegociables.
**Frontend:** SPA sobre la API (Vue 3 + Inertia o Vue puro + Pinia — ver §10), responsivo, sin recarga de página entre módulos.
**Infraestructura:** Docker (ya presente en el repo) → mismo contenedor corre en cualquier VPS/nube; colas para BCCR, revaluación, envío de reportes, IA.

---

## 2. Usuarios, roles y derechos (RBAC)

### 2.1 Tres niveles jerárquicos

| Tipo | Alcance | Puede otorgar permisos |
|---|---|---|
| **Super usuario** | Global, todas las compañías del tenant | Sí, sin restricción, incluye delegar la facultad de otorgar autorizaciones |
| **Administrador** | Una o varias compañías asignadas | Solo si el super usuario le delegó esa facultad explícitamente (`can_grant_permissions`) |
| **Usuario final** | Función/es asignadas | No |

**Decisión propuesta:** no modelar esto como 3 roles fijos en código, sino como un **flag `can_grant_permissions`** sobre cualquier asignación de rol, más un rol de sistema `super_admin` no editable. Así "administrador con facultad de otorgar autorizaciones específicas" (que el brief pide explícitamente) es solo un administrador con ese flag en `true`, sin crear un cuarto tipo de usuario.

### 2.2 Modelo de permisos — dos capas, igual que el legado que mostrás en las imágenes

**Capa A — Derechos generales por módulo/pantalla** (imagen 1): Lectura y escritura / Lectura / Ninguno / Derechos de grupo. Se aplica a menús y pantallas no transaccionales (catálogos, reportes, configuración).

**Capa B — Derechos por tipo de documento** (imagen 3), mucho más granular porque cada documento tiene consecutivo, rango de fechas y estados:

- `create`, `modify`, `delete`, `void` (anular)
- `vary_consecutive` (alterar el número siguiente)
- `backdate` (digitar fechas fuera de rango)
- `modify_integrated_documents` (editar documentos que ya generaron un asiento/otro documento)
- `create_out_of_range` (crear fuera del rango de numeración)
- `modify_document_dates`
- Alcance: **Individual** o **Grupal** (aplica a un usuario o a todo un grupo/rol de una vez)

Tablas: `roles`, `permissions`, `role_permissions`, `user_roles` (con `company_id` para permisos por-compañía), y **`document_type_permissions`** (sujeto = user_id o role_id, `document_type_id`, los 8 booleanos de arriba). Un usuario puede tener Lectura/Escritura general sobre "Bancos" pero, dentro de Bancos, no tener `void` sobre CKB específicamente — la capa B siempre puede restringir más que la capa A, nunca ampliarla.

### 2.3 La regla innegociable de eliminar

Ya está fijada en `CLAUDE.md`: **eliminar movimientos contabilizados solo lo puede hacer el super usuario, y esta facultad no es delegable.** Esto se implementa como una excepción *hardcodeada* en la Policy (`JournalEntryPolicy::delete()`), no como un permiso más en `document_type_permissions` — si estuviera en la tabla, un super usuario podría (por error o de mala fe) otorgarlo a alguien vía UI, violando la regla. Por eso vive en código, fuera del alcance de cualquier pantalla de configuración.

---

## 3. Motor de documentos (configurable por el usuario, no por código)

Este es el corazón de la extensibilidad que pedís: **los tipos de documento son datos**. Un administrador crea `FVE`, `NCC`, `TRB`, `ADD`, etc. desde una pantalla, no un desarrollador desde una migración.

### 3.1 `document_types`

| Campo | Propósito |
|---|---|
| `code` (3 chars, único por compañía) | FVE, NCC, DVC, NDC, TRB, CKB, DEB, ADD, ADC, ACC... |
| `name`, `description` | |
| `origin_module` | `ventas`, `compras`, `bancos`, `contable`, `cxc`, `cxp`, `activos_fijos` (futuro), etc. — de dónde "proviene" el documento, tal como pedís |
| `generates_journal` (bool) | si al confirmarse crea automáticamente un `journal_entry` |
| `default_debit_account_id` / `default_credit_account_id` | opcional, para documentos con contrapartida fija (ej. TRB siempre contra el banco origen) |
| `numbering_mask`, `next_consecutive`, `range_from`, `range_to` | numeración configurable |
| `consecutive_on_save` (bool) | |
| `allow_out_of_range_dates`, `prevent_admins_out_of_range` (bool) | replica exacta de la imagen 4 |
| `date_range_from`, `date_range_to` | rango válido de fechas del documento |
| `currency_mode` | `local_fija` / `extranjera_fija` / `libre` (imagen 6: "Inicial: Colones/Dólares, Fija") |
| `allows_balance_increase` | "Permitir Aumentos al Saldo del Vencimiento" (imagen 6) — relevante para NDC/NCC sobre partidas abiertas |
| `reads_document_classifications` | flag de imagen 6 |
| `status` | activo/inactivo |

Tablas satélite, reflejando exactamente las pestañas que mostraste:

- `document_type_printing` — estación de trabajo, impresora asignada, imprimir al grabar, impresión directa, confirmar reimpresión, resumir líneas, archivo de formato (imagen 5).
- `document_type_officers` — "Funcionarios": qué rol (elaboró/revisó/aprobó) es obligatorio para ese documento (workflow de aprobación simple).
- `document_type_visible_columns` — qué columnas se muestran por defecto en listados de ese documento (imagen "Columnas visibles").
- `document_type_security` (= la pestaña "Seguridad") — referencia a `document_type_permissions` de §2.2.

### 3.2 Numeración

Cada documento se guarda con `document_number` correlativo **dentro de su `document_type_id` + `company_id`**, generado atómicamente (lock pesimista o `SELECT ... FOR UPDATE` sobre el contador) para evitar duplicados en concurrencia — este es un punto donde el legado que mostrás falla fácilmente si no se cuida.

---

## 4. Contabilidad: catálogo, asientos y triple moneda estilo SAP B1

### 4.1 Catálogo de cuentas

Estructura de 5 grupos configurable por compañía, tal como en la imagen 7:

```
x-xx-xx-xx-xxx   →  Clase-Grupo-Subgrupo-Cuenta Mayor-Auxiliar (ejemplo)
```

`account_mask_config` (por compañía) guarda el arreglo de longitudes de segmento `[1,2,2,2,3]`, para que cada empresa pueda ajustar su propia máscara sin tocar código — igual filosofía que documentos-como-datos.

`chart_of_accounts`: `code`, `parent_id` (árbol), `level`, `description_es`, `description_en`, `account_type` (activo/pasivo/patrimonio/ingreso/gasto), `normal_balance` (débito/crédito), `accepts_posting` (bool — **solo cuentas hoja reciben movimiento**, regla ya fijada), `currency_mode` (local/extranjera/ambas), `is_financial_report` , `section`, `list_order`, `tax_classification` (ver §6), `is_active`.

### 4.2 Asientos y triple moneda (imagen del corte bancario + inspiración SAP B1)

Cómo lo resuelve SAP Business One, y cómo se propone replicarlo:

- **Moneda local (LC)** — moneda funcional del país (₡ Colón para Costa Rica).
- **Moneda extranjera (FC)** — la moneda extranjera relevante de esa línea (usualmente USD, pero el catálogo admite más de una FC configurada por compañía).
- **Moneda de sistema (SC)** — moneda "ancla" fija (típicamente USD o EUR "duro", nunca se re-expresa), que sirve para comparar compañías o consolidar grupos con distinta moneda local, y no se usa para registrar directamente, solo se calcula.

Cada `journal_detail` guarda **tres pares débito/crédito** (`debit_local/credit_local`, `debit_foreign/credit_foreign`, `debit_system/credit_system`) más el `exchange_rate` LC↔FC y FC↔SC vigente **al momento del registro** (histórico, inmutable). La partida doble se valida en las tres monedas simultáneamente antes de permitir posting — igual que en SAP B1, para que nunca se descuadre ni siquiera en la moneda de sistema.

**Decisión propuesta:** si una línea nace en LC, el sistema calcula FC y SC con el TC vigente de esa fecha; si nace en FC, calcula LC y SC. El usuario nunca digita las tres, solo la moneda "natural" del documento (definida en `document_types.currency_mode`); las otras se derivan.

### 4.3 Tipos de cambio — API del BCCR

Job programado diario (`FetchBccrRatesJob`) que consulta el **Servicio Web de Indicadores Económicos del BCCR** (TC compra/venta oficial, indicador 317/318) y guarda en `exchange_rates` (`currency_id`, `date`, `rate_type` [compra/venta/referencia], `rate`, `source` = `bccr_api` | `manual`, `created_by`, `is_locked`). Regla ya fijada: histórico inmutable; una corrección manual **no sobrescribe**, inserta un nuevo registro auditado con motivo.

### 4.4 Períodos fiscales y bloqueo

`fiscal_years` (año, estado abierto/cerrado) → `fiscal_periods` (mes, estado: **Abierto / Cerrado / Bloqueado**). Regla ya fijada: no se registra en Cerrado/Bloqueado. Se distingue **Bloqueado** (temporal, reversible por Admin con facultad) de **Cerrado** (definitivo, solo reversible por Super usuario reabriendo con asiento de auditoría) — esto responde directamente a tu pedido de "desbloqueos de períodos aún sin cerrar" como proceso distinto de reabrir un cierre anual.

---

## 5. Socios de negocio y Cuentas por Cobrar/Pagar

### 5.1 Codificación

`business_partners.code` alfanumérico de dos grupos `x-xxx` (ej. `C-001` cliente, `P-014` proveedor), único por compañía. `type`: cliente / proveedor / ambos.

`bp_categories` y `bp_families` — tablas simples por compañía, para que el usuario las configure libremente (igual filosofía de "todo es dato configurable"), y cada socio referencia una categoría y una familia. Cada socio liga a **una cuenta contable específica del catálogo** (`gl_account_id`) — así el mayor de CxC/CxP se subdivide por socio sin necesitar una cuenta contable por cliente.

### 5.2 Partidas abiertas y aplicación de pagos

- `bp_open_items`: nace de cada `journal_detail` que afecta la cuenta de un socio (una FVE genera una partida abierta por su monto; un pago la cierra parcial o totalmente). Campos: monto original, moneda, saldo pendiente, fecha de vencimiento, estado (abierta/parcial/cerrada).
- `bp_payment_applications`: registra qué asiento de pago (TRB/CKB/DEB o un recibo de cliente) se aplicó contra qué partida abierta, por cuánto monto y a qué tipo de cambio (relevante si el pago es en otra moneda que la factura original — genera automáticamente la diferencia cambiaria realizada, distinta del diferencial cambiario de cierre que es no realizado).

Esto habilita justo lo que pedís: "documentos abiertos para que posteriormente se puedan dar los aplicas por cobros u otros motivos".

---

## 6. IVA y reportes fiscales (Costa Rica)

Costa Rica opera con **Ley del IVA (9635)**: tarifa general 13%, tarifas reducidas 4%/2%/1%/0.5%, exento, y el régimen de IVA que distingue **débito fiscal (ventas)** y **crédito fiscal (compras)**, declarado mensualmente en el **formulario D-104** ante el Ministerio de Hacienda.

- `tax_types` (IVA; deja espacio para futuros: Renta, Selectivo de Consumo).
- `tax_rates` (código, nombre, porcentaje, vigente desde/hasta — las tarifas han cambiado por transitorios de la ley, por lo que se versionan por fecha, nunca se edita una tarifa histórica).
- `chart_of_accounts.tax_classification`: **Ninguno / Ventas / Compras / IVA General / IVA Devengado / IVA Soportado** — exactamente el combo de tu imagen 9. Esto permite que, al contabilizar, el sistema separe automáticamente la base imponible del impuesto según qué cuenta se está tocando.
- `journal_detail_taxes`: por cada línea de asiento marcada con impuesto, guarda base gravable + monto de impuesto + tarifa aplicada, para poder generar el reporte de compras/ventas y el borrador del D-104 sin recalcular contra el libro diario completo cada vez.

**Decisión propuesta:** el reporte fiscal se genera *leyendo* `journal_detail_taxes` (nunca se recalcula desde journal_details en crudo), porque así una corrección posterior de tarifa no reescribe silenciosamente una declaración ya presentada — el reporte histórico queda fijo salvo que se regenere explícitamente.

---

## 7. Diferencial cambiario

`FxRevaluationService`, disparado manualmente o programado al cierre de mes:

1. Toma todas las cuentas con `currency_mode = extranjera` (incluye los saldos de socios en FC) con saldo abierto a la fecha de corte.
2. Para cada una, compara el **monto en LC al tipo de cambio histórico de registro** (el que ya vive en `debit_local/credit_local` de cada línea) contra el **monto en LC al tipo de cambio de la fecha de corte** elegida.
3. La diferencia genera un asiento automático con el `document_type` que la compañía configure (ej. `ADC`), contra la cuenta de ganancia/pérdida cambiaria que también se configura por compañía.
4. El asiento generado es reversible al primer día del período siguiente (patrón estándar: se contabiliza y se revierte, para no arrastrar el ajuste no realizado permanentemente en el saldo).

Tablas: `fx_revaluation_runs` (corte, TC usado, documento generado, ejecutado por/cuándo) y `fx_revaluation_details` (detalle cuenta por cuenta/socio por socio, saldo FC, LC histórico, LC revaluado, diferencia).

---

## 8. Bancos y conciliación

`bank_accounts` liga 1-a-1 con una cuenta hoja del catálogo (`gl_account_id`) — así el banco no es un módulo aparte, es una vista especializada sobre esa cuenta contable.

Replicando tu pantalla de corte (imagen 8):
- `bank_reconciliations` (cuenta bancaria, fecha de corte, saldo según banco, saldo según libros, depósitos no acreditados, cheques no pagados, créditos/débitos del banco no registrados, saldo actual — estos últimos 4 son *calculados*, no capturados, a partir de qué líneas quedaron sin marcar).
- `bank_reconciliation_lines`: cada `journal_detail` de esa cuenta, con dos checkboxes independientes (`matched_in_books`, `matched_in_bank`) — igual que las columnas "Cta" y "Bco" de tu imagen.
- `bank_statement_lines`: para import de estado de cuenta (CSV/MT940/API del banco a futuro) y conciliación semiautomática por monto+fecha.

---

## 9. Escalabilidad modular y IA

### 9.1 Módulos futuros

`company_modules` (company_id, module_code, enabled_at) — controla qué módulos ve cada compañía (Planillas, Inventarios, Facturación electrónica, Activos Fijos). Cada módulo futuro se integra generando sus propios `document_types` que escriben contra el mismo `PostJournalService` — nunca duplican el motor de asientos. Esto es lo que te permite decir hoy "solo necesito CxC" y en 8 meses activar Planillas sin migrar nada del core.

### 9.2 Integración de IA (API key configurable)

`ai_provider_settings` (por compañía): proveedor (Anthropic/OpenAI), API key cifrada en reposo (`encrypted` cast de Laravel), modelo, features habilitadas, límite de gasto mensual. `ai_usage_logs` para trazabilidad/costo.

Casos de uso concretos para la v1, todos como **asistencia sobre el mismo Service, nunca bypaseándolo**:
- **Captura asistida**: el usuario describe la transacción en lenguaje natural ("pagué la factura 445 del proveedor Ferretería X por transferencia") → la IA propone el documento (`TRB`, socio, monto, cuenta) → el usuario confirma → se llama el Service normal.
- **Clasificación de cuenta**: al importar líneas de banco o facturas, sugerir la cuenta contable / clasificación de IVA más probable según histórico.
- **Reportes en lenguaje natural**: "muéstrame el diferencial cambiario de julio" → la IA arma el query sobre el modelo ya existente (nunca ejecuta SQL libre contra la BD; usa un set acotado de consultas/reportes predefinidos).
- **Explicación de saldos**: dado un asiento o una cuenta, explicar en español por qué el saldo es el que es (para usuarios no contadores).

---

## 10. Frontend

**Decisión propuesta — stack:** Vue 3 + Inertia.js sobre Laravel (evita duplicar auth/routing en una SPA separada, mantiene un solo repo, y es el patrón más productivo para un equipo pequeño manteniendo un ERP grande). Componentes con Pinia para estado de sesión/compañía activa.

**Layout** (según tu segunda imagen, pero adaptado):
- Barra lateral izquierda con el listado de módulos/pantallas, **colapsable/fija** (toggle persistido en `localStorage` y en preferencia de usuario).
- Barra horizontal superior de **acciones del documento activo** (nuevo, guardar, buscar, imprimir, anular, actualizar, exportar) — cambia según el contexto, igual que la barra de íconos de tu imagen 2.
- Selector de compañía activa siempre visible en el header (cambio de compañía sin logout).

**Tema:** primario azul marino profundo (`#0B1F3A` aprox.), secundario blanco mármol (superficie con textura sutil vía CSS, no imagen pesada), con modo claro y oscuro vía `prefers-color-scheme` + toggle manual, tokens de diseño en SCSS/CSS variables (ya hay `sass-embedded` instalado). Tipografía y densidad de tabla pensadas para grillas contables (muchas filas, números alineados a la derecha, tabulares).

---

## 11. Procesos contables de cierre y auditoría

- **Cierre mensual**: valida que el período no tenga documentos en borrador, corre (opcionalmente) el diferencial cambiario, y cambia `fiscal_periods.status` a Cerrado. Reversible solo por Super usuario.
- **Cierre anual**: genera el asiento de cierre (`ACC`) que traslada saldos de resultados a utilidades acumuladas, abre el año siguiente con `opening_balances` derivados automáticamente (no se re-digitan).
- **Carga de saldos iniciales**: `opening_balances` — pantalla/importador para la puesta en marcha de una compañía nueva o migración desde el sistema legado, por cuenta y opcionalmente por socio de negocio, en las 3 monedas.
- **Reconciliación de cuentas**: reporte de auxiliar por cuenta (para las cuentas de mayor uso: bancos, CxC, CxP) que cruza saldo contable vs. detalle de partidas abiertas.
- **Auditoría** (`audit_logs`): toda escritura relevante (creación, modificación, anulación, cambio de permisos, cambio de período) queda registrada con usuario, IP, valores antes/después. Ya fijado en `CLAUDE.md` — nada contabilizado se borra físicamente, solo Borradores.

---

## 12. Roadmap sugerido

| Fase | Contenido |
|---|---|
| **0 — Fundaciones** | Companies, Users, Roles/Permissions, DocumentTypes (motor completo), ChartOfAccounts, Currencies/ExchangeRates, JournalEntries/Details (triple moneda), FiscalPeriods |
| **1 — CxC/CxP** | BusinessPartners, OpenItems, PaymentApplications, documentos FVE/NCC/DVC/NDC |
| **2 — Bancos** | BankAccounts, Reconciliation, documentos TRB/CKB/DEB, integración BCCR |
| **3 — Fiscal y cierre** | IVA (tax_types/rates/classification), reportes D-104, diferencial cambiario (ADC), cierre mensual/anual (ACC), opening balances |
| **4 — IA** | ai_provider_settings, captura asistida, reportes en lenguaje natural |
| **5 — Escalables** | Facturación electrónica CR (Hacienda v4.4), Inventarios, Planillas, Activos Fijos — cada uno como módulo nuevo sobre el mismo core |

---

## 13. Decisiones confirmadas (2026-08-04)

1. **Frontend:** Vue 3 + Inertia.js sobre Laravel.
2. **Moneda de sistema (SC):** USD fija para todo el tenant (no configurable por compañía).
3. **IA:** Anthropic (Claude) como proveedor por defecto, con BYOK opcional por compañía en `ai_provider_settings`.
4. **Facturación electrónica de Hacienda:** queda fuera del alcance de la Fase 1 (CxC); se aborda en la Fase 5 como módulo separado.

Ver `docs/modelo-datos.dbml` para el esquema completo de base de datos que respalda esta propuesta, y `docs/decisiones.md` para el registro histórico de decisiones del proyecto.
