# Fuentes oficiales

Lo que manda sobre cualquier otra cosa (la guía, la memoria, este código): los XSD v4.4 y la política de firma.

## XSD v4.4 — `resources/hacienda/xsd/v4.4`

Los **oficiales de Hacienda**, de la página de Anexos y Estructuras del ATV
(`atv.hacienda.go.cr/ATV/ComprobanteElectronico/frmAnexosyEstructuras.aspx`, enlaces
`docs/esquemas/2024/v4.4/<Tipo>_V4.4.xsd`), descargados el 2026-10-18.

Única modificación: el `xs:import` de la firma apunta a `../../xmldsig-core-schema.xsd` en el servidor de Hacienda; acá
apunta a `xmldsig-core-schema.xsd`, la copia local del esquema del W3C (Hacienda no la publica en esa ruta). Nada más
cambia, byte a byte.

| Archivo en el repositorio | Archivo oficial | SHA-256 del oficial |
|---|---|---|
| facturaElectronica.xsd | FacturaElectronica_V4.4.xsd | `d384afef665573606f6499b2182d6070850ada8c93bc40fa7f0f3901a25b9cc8` |
| facturaElectronicaCompra.xsd | FacturaElectronicaCompra_V4.4.xsd | `ae1e5b782b568d225b34e046858012ed808b2587fc5b1b5161fa707b4bdd8253` |
| facturaElectronicaExportacion.xsd | FacturaElectronicaExportacion_V4.4.xsd | `d710032a05cd3d41272c0cceb8130b4add37ce139b491dbaa7bd6a59163809bd` |
| mensajeReceptor.xsd | MensajeReceptor_V4.4.xsd | `37bc1ffcf06a66a5a0b63b2908740dbd8f63fe0a4120426ea6a957862355c1c3` |
| notaCreditoElectronica.xsd | NotaCreditoElectronica_V4.4.xsd | `9af7dff4ee0c2787f8fc30cb63aef37b574c96846007be327d442e30e0ddf804` |
| notaDebitoElectronica.xsd | NotaDebitoElectronica_V4.4.xsd | `ac2c63f93602502af22980a81f26032c6a561ed3a8c516ac28184ce1433b7b61` |
| tiqueteElectronico.xsd | TiqueteElectronico_V4.4.xsd | `cda1c7dd97f9a235111c29948f05d7893864663cd73792fd3b51e3dae582cfdb` |

Los hashes de los archivos tal como quedaron en el repositorio están en `resources/hacienda/xsd/v4.4/SHA256SUMS`
(`sha256sum -c SHA256SUMS`).

**Historia.** La primera versión se tomó del paquete público `bigstarlee058/factura-electronica` (GitHub), porque el
CDN de Hacienda no permitía la descarga automatizada. Al compararla con la oficial del ATV resultó ser una revisión
anterior: `Proporcion` y `ProporcionSurtido` con 5 dígitos y 2 decimales (la oficial: 10 y 5), una sola `ds:Signature`
(la oficial: hasta 5), `NumTelefono` desde 10000000 (la oficial: desde 100), la cédula del MensajeReceptor de 9 a 12
dígitos (la oficial: hasta 20 caracteres), y monedas que la oficial ya no acepta (XAU, XAG, XPD, XPT, XBA-XBD, XTS,
XXX). Todo lo que Pivox genera valida igual con las dos.

Las etiquetas de los catálogos de `FiscalCatalogs` salen de la documentación de cada `<xs:enumeration>` de estos XSD.

## Anexos y estructuras v4.4

`docs/esquemas/2024/v4.4/ANEXOS Y ESTRUCTURAS_V4.4.pdf` en el ATV (SHA-256
`2e36bd1101bbcabbb391ab1ec8ffc3773b93031341c7d98a05a5d0f0887d4305`): las condiciones de cada campo y las notas de
los catálogos. La Nota 14 remite a la tabla de ubicación.

## División territorial — `resources/hacienda/ubicacion/v4.4.php`

La tabla oficial de la Nota 14 (provincia, cantón, distrito y barrio), `docs/esquemas/2024/v4.4/Codificacionubicacion_V4.4.rar`
en el ATV. Adentro viene `Codificacionubicacion_V4.4.xlsx`; los hashes de los dos están en
`resources/hacienda/ubicacion/SHA256SUMS`. De ahí se generó el catálogo (7 provincias, 84 cantones, 492 distritos) que
usan `CostaRicaTerritory` y las listas de la pantalla. Única corrección a mano: «San Sebástian» → «San Sebastián».

## Actividades económicas (CIIU 4, TRIBU-CR) — `resources/hacienda/actividades/ciiu4.php`

La tabla oficial de correspondencia ATV (CIIU 3) → TRIBU-CR (CIIU 4), publicada en
`www.hacienda.go.cr/ClasificacionActividadesEconomicas.html`:
`docs/CorrespondenciaATVCIIU3-TRIBUCRCIIU4-Completo.pdf` (y la del Régimen Simplificado, `…-RTS.pdf`, que es un
subconjunto). Hashes en `resources/hacienda/actividades/SHA256SUMS`. Las celdas se extrajeron por tabla (no por texto:
con `pdftotext -layout` las celdas de varias líneas se corren de fila) y de cada fila se tomaron el código y el nombre de
la subclase TRIBU-CR: 367 códigos, cada uno con un único nombre. Lo usan `EconomicActivityCatalog` y las listas de
«Nueva actividad económica».

El API público no publica el catálogo (solo las actividades de una cédula, `/fe/ae`); las que una cédula tenga
inscritas y no estén en la tabla se ofrecen igual.

## Catálogo CAByS 2025 — `resources/hacienda/cabys/cabys-2025.php`

El archivo oficial del Banco Central, vigente para Hacienda desde el 1 de junio de 2025:
`www.bccr.fi.cr/content/dam/bccr/noticias/2025/2025-04-01-catalogo-de-bienes-servicios-1.xlsx` (anunciado en la
noticia del BCCR «Actualización del Catálogo de Bienes y Servicios (Cabys) 2025», circular CP-BCCR-015-2025). Hash en
`resources/hacienda/cabys/SHA256SUMS`. De la hoja «Catálogo» se tomaron el código de 13 dígitos («Categoría 9»), su
descripción y el «Impuesto»: 20 506 códigos, la tarifa en porcentaje (13, 4, 2, 1), «exento» (885) o «na» (3
transferencias). En el Excel la tarifa viene como `0.13`, a veces como `13%`; se normalizó. Los nombres de las 10
categorías de primer nivel van aparte (`sections`). Lo usa `CabysCatalog`.

## API público de Hacienda — `HaciendaPublicData`

Sin credenciales y solo de lectura (`config/hacienda.php`, `public_api`):

| Consulta | Devuelve | Lo usa |
|---|---|---|
| `GET api.hacienda.go.cr/fe/cabys?codigo=<13 dígitos>` | `[]` si no existe; si existe, una lista con él | un código que no está en el catálogo local (por si es más nuevo): la ficha del artículo y la revisión antes de emitir (-400) |
| `GET api.hacienda.go.cr/fe/ae?identificacion=<cédula>` | `{nombre, tipoIdentificacion, regimen, situacion, actividades: [{estado, tipo, codigo: "6209.0", descripcion}]}`; 404 si no la tiene | las actividades inscritas (-408) y la consulta de un socio |

`impuesto` es la tarifa de IVA del CAByS, en porcentaje. El primer dígito del código es la sección de la CPC: 0-4
bienes, 5-9 servicios, que es como Hacienda reparte las líneas en el resumen (-111).

## Política de firma XAdES-EPES

- Identificador: `https://cdn.comprobanteselectronicos.go.cr/xml-schemas/Resoluci%C3%B3n_General_sobre_disposiciones_t%C3%A9cnicas_comprobantes_electr%C3%B3nicos_para_efectos_tributarios.pdf`
- Descargado del CDN oficial el 2026-10-18 (163 479 bytes, empieza con `%PDF-1.7`).
- `SigPolicyHash` (SHA-256 en base64), **calculado** sobre ese archivo:

  ```
  openssl dgst -sha256 -binary politica.pdf | openssl base64
  DWxin1xWOeI8OuWQXazh4VjLWAaCLAA954em7DMh0h8=
  ```

Vive en `config/hacienda.php` (`signature_policy`). Si Hacienda publica otra política, cambian la URL y el hash juntos.

## Endpoints

`config/hacienda.php`, tomados de la implementación de referencia (aceptada en el sandbox):

| | Pruebas (`stag`) | Producción (`prod`) |
|---|---|---|
| IDP | `idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/…/token` | `…/realms/rut/…/token` |
| Recepción | `api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1` | `api.comprobanteselectronicos.go.cr/recepcion/v1` |
| client_id | `api-stag` | `api-prod` |

Se pueden cambiar con variables de entorno (`HACIENDA_IDP_URL_STAG`, etc.) si Hacienda los mueve.

## Normativa

- Resolución MH-DGT-RES-0027-2024: disposiciones técnicas de la versión 4.4 (suspende el QR de la representación gráfica).
