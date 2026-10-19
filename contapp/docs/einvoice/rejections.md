# Bitácora de rechazos de Hacienda

Una fila por cada rechazo nuevo. Los de la implementación anterior (L1 a L21) están en la guía, secc. 3.9, y ya tienen
su prueba en `tests/Feature/Billing/Hacienda`.

**Ante un rechazo:**

1. Leer el motivo: `X-Error-Cause` (envío rechazado al recibir) o `DetalleMensaje` del MensajeHacienda (procesado y
   rechazado). Los dos quedan en la historia del comprobante (`electronic_document_exchanges`) y se ven en su pantalla.
2. Ubicar la regla en el XSD v4.4 o en el documento «Anexos y estructuras».
3. Corregir el código.
4. Agregar la prueba que reproduce el caso.
5. Anotarlo acá.
6. Volver a emitir con consecutivo y clave nuevos («Emitir de nuevo»).

| Fecha | Síntoma (mensaje de Hacienda) | Causa | Corrección | Prueba |
|---|---|---|---|---|
| 2026-10-18 | **-408** El código de actividad del emisor no es válido según el catálogo del RUT (tiquete de prueba, sandbox). | La actividad configurada (6209.9) no es la que la cédula tiene inscrita en Hacienda (6209.0). Nada lo revisaba antes de enviar. | `HaciendaPublicData::taxpayer` lee las actividades inscritas (api.hacienda.go.cr/fe/ae). `ElectronicPreflight` no emite con una actividad no inscrita, y Parámetros de facturación marca cada actividad «Inscrita / No inscrita» y ofrece agregar las inscritas. | `HaciendaPublicDataTest`: «no emite con una actividad que el emisor no tiene inscrita» |
| 2026-10-18 | **-400** El CAByS de la línea 1 no existe en el catálogo. | El CAByS del artículo de ejemplo (8471600000000) era inventado: el seeder de demostración traía códigos con forma de arancel, no del catálogo. | `HaciendaPublicData::cabys` lo consulta (api.hacienda.go.cr/fe/cabys). La ficha del artículo no guarda un código que no existe; `ElectronicPreflight` no emite con uno, ni «Emitir de nuevo» una venta que lo tiene guardado; el buscador `CabysPicker` busca por descripción en la ficha y en la línea. Los artículos de ejemplo pasaron a códigos reales. | `HaciendaPublicDataTest`: «no emite con un CAByS que no está en el catálogo», ««Emitir de nuevo» no gasta otro consecutivo…», «la ficha del artículo no guarda un CAByS que Hacienda dice que no existe» |
| 2026-10-18 | **-111** (dos veces) El total de servicios gravados debía ser 1200 y el de mercancías gravadas 0. | Hacienda clasifica la línea en servicio o mercancía por el **primer dígito del CAByS** (secciones CPC 0-4 bienes, 5-9 servicios), no por lo que diga el emisor. El calculador usaba `is_service` (si el artículo es inventariable), y el código inventado empezaba en 8. | `SalesTotalsCalculator` clasifica con `FiscalCatalogs::cabysIsService`. `is_service` sigue decidiendo lo del ERP (bodega, cuenta de ingreso). | `HaciendaPublicDataTest`: «Hacienda clasifica por el CAByS…» y «…una línea de servicio con CAByS de bienes va a mercancías» |
