# Facturación electrónica — Pivox

Implementación de la emisión de comprobantes electrónicos v4.4 ante el Ministerio de Hacienda, a partir de la guía
`../../../guia_agente_facturacionElectronica_Hacienda.md` (la implementación de referencia en App Arrendamientos). Las
reglas de trabajo están en `CLAUDE.md` secc. 38.

- `01-fuentes.md` — las fuentes oficiales, con su SHA-256 y de dónde salieron.
- `rejections.md` — bitácora viva de rechazos de Hacienda: síntoma → causa → corrección → prueba.

## Decisiones (Fase 0 de la guía, Apéndice G)

| # | Decisión | Lo que se hizo | Por qué |
|---|---|---|---|
| 1 | Stack | El de Pivox: Laravel 13, PHP 8.5, Inertia + Vue 3, MySQL, cola en base de datos. Firma con DOM + `openssl`, sin librerías nuevas. | Monolito modular (secc. 2). Una librería XMLDSig genérica no arma la parte XAdES de todos modos. |
| 2 | Modelo de negocio | SaaS multiempresa. **Cada compañía con sus credenciales**, que carga solo su Superusuario. | Pedido del usuario. `ProveedorSistemas` sale de `HACIENDA_SYSTEM_PROVIDER_ID` (la cédula de quien provee Pivox); vacío, la del emisor. |
| 3 | Tipos de comprobante | Factura (01), nota de débito (02), nota de crédito (03), tiquete (04) y exportación (09). | La factura de compra (08) la emite quien compra (Compras). El recibo de pago (10) no tiene XSD publicado con los demás. |
| 4 | Custodia de secretos | Cast `encrypted` de Laravel (APP_KEY), en la base; nada en el disco ni en el `.env`. | No hay KMS en la infraestructura actual. Si cambia la APP_KEY, el Superusuario vuelve a cargar las credenciales. |
| 5 | Firma | Implementación propia (`XadesEpesSigner`), verificada por un verificador independiente (`XmlSignatureVerifier`). | Mismo algoritmo que la referencia aceptada en sandbox, con la política explícita que la implementación anterior no tenía (C2). |
| 6 | PDF y correo | dompdf y el SMTP de la instalación. | Ya están en el proyecto (comprobante de pago de planilla). |
| 7 | Archivo legal | El XML firmado y el MensajeHacienda en la base (`electronic_documents`, con SHA-256), solo agregar. | Sin almacenamiento WORM por ahora. Pendiente: copia en el bucket con retención. |
| 8 | Tipo de cambio | El del comprobante (el que ya usa Pivox, del BCCR o digitado). | Sin cambios. |
| 9 | Clasificación tributaria | Se mantiene la de Pivox: tarifa 10 exento, 11 no sujeto, el resto gravado; exonerado según su documento. | **Pendiente de validar con un contador** (guía, punto de control de la Fase 6). |
| 10 | Integración | El comprobante electrónico nace de la venta de siempre (`PostSalesDocumentService`), en la misma transacción. | Sin un segundo flujo de facturación que mantener. |
| 11 | Contingencia | No implementada: situación 1 (normal). | Fase 14 de la guía. |
| 12 | Producción | Por compañía, la enciende y la pasa a producción su Superusuario. | Pedido del usuario. |

## Lo que cubre, contra la guía

**Implementado (fases 1 a 12, para 01/02/03/04/09):**

- XSD oficiales versionados y validación en tiempo de ejecución, antes y después de firmar.
- Catálogos tomados de las enumeraciones del XSD (`FiscalCatalogs`): unidades, condiciones de venta, tarifas, exoneraciones, referencias.
- Consecutivo por sucursal, terminal y tipo con `FOR UPDATE` (A4), clave con la fecha de emisión (L14), reemisión con clave nueva (L15, L16).
- XML corregido: namespace por tipo (C1), CAByS obligatorio (C3), sin montos negativos (C4), totales sobre el monto bruto (A1), código y naturaleza del descuento (A2), unicidad por compañía y ambiente (A3), tarifas completas (A8), plazo real (A10), monto real por medio de pago (A11), «Otros» descritos (A12), XSD local (A13).
- Firma XAdES-EPES con política y hash (C2, L17), emisor en orden RFC 4514 (M6).
- Llave cifrada y revisada al cargarla (A14). Fecha de emisión real (A15). Nota solo sobre un comprobante aceptado (A16).
- Máquina de estados con transiciones explícitas que lanzan (A6), reconciliación antes de reenviar (A5), backoff con tope (A7), cola real y programador (C5).
- Historia append-only de estados e intercambios (M1), `X-Error-Cause` (M9), PDF y correo al cliente (M2), sucursal y terminal por emisor (M10).

**Pendiente (fases 13 a 17):**

- Mensaje Receptor (aceptar o rechazar comprobantes de proveedores) y bandeja de recibidos.
- Factura electrónica de compra (08) desde Compras, recibo electrónico de pago (10).
- Otros cargos, IVA cobrado a nivel de fábrica, impuestos específicos con sus datos.
- Contingencia (situaciones 2 y 3).
- Archivo WORM en el bucket.
- Certificación en el sandbox con la matriz 7.4 de la guía: **la hace el usuario** (no se emitió nada contra Hacienda durante la implementación).

## Puntos a confirmar contra la fuente oficial (⚠️ en la guía)

- `TotalImpuesto` y `TotalDesgloseImpuesto` se mandan **netos de exoneración** (para que la suma del desglose sea TotalImpuesto). Confirmar en el sandbox con una exoneración.
- El reparto de una exoneración parcial entre gravado y exonerado (`MontoTotal × tarifa exonerada / tarifa`).
- Que la línea «no sujeta» va con la tarifa 11.
- El primer envío al sandbox es la prueba de la firma (Fase 8): si Hacienda la rechaza, anotar en `rejections.md` el `X-Error-Cause` y el `DetalleMensaje`.
