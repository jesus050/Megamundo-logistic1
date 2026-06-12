# Checklist de Pruebas de Calidad (QA) - MegaMundo Logística

Este documento detalla las pruebas funcionales, de seguridad e integridad que deben ejecutarse en entornos de Staging y antes de la entrega final a Producción.

---

## 1. Pruebas de Roles y Permisos

| ID | Caso de Prueba | Entrada / Acción | Resultado Esperado | Estado |
|---|---|---|---|---|
| **RP-01** | Acceso de Administrador | Iniciar sesión como Administrador y navegar al plugin. | Acceso completo a Ajustes, Lotes, Impresión térmica, y Escáner. | [ ] |
| **RP-02** | Acceso de Ingresador | Iniciar sesión como `mm_ingresador`. Navegar a un lote. | Visualiza y edita cantidades, costos, precios. Puede imprimir etiquetas y descargar CSV. No puede acceder a Ajustes generales. | [ ] |
| **RP-03** | Restricción Financiera del Contador | Iniciar sesión como `mm_contador`. Acceder a un lote en admin. | No debe visualizar columnas de costos, precios, ni márgenes en el editor del lote en WordPress. | [ ] |
| **RP-04** | Acceso del Contador al Escáner | Iniciar sesión como `mm_contador`. Ir a la URL del escáner. | Acceso exitoso a la pantalla móvil de escaneo. Permite escaneo físico de ítems de forma limpia. | [ ] |
| **RP-05** | Bloqueo REST del Contador | Intento de enviar costos/precios REST con el rol Contador. | El servidor responde con código `403 Forbidden` / Mensaje de permisos insuficientes. | [ ] |

---

## 2. Pruebas del Escáner de Bodega

| ID | Caso de Prueba | Entrada / Acción | Resultado Esperado | Estado |
|---|---|---|---|---|
| **ESC-01** | Escaneo Exitoso | Introducir/escanear SKU válido y presionar Enter. | El producto se añade a la tabla con cantidad `1`. Al repetir, la cantidad incrementa a `2`. | [ ] |
| **ESC-02** | SKU Inexistente | Escanear un SKU que no existe en WooCommerce. | Muestra una alerta visual clara indicando que el producto no se encuentra registrado en el sistema. | [ ] |
| **ESC-03** | Transición Offline a Online | Desactivar conexión Wi-Fi, escanear 3 ítems, reactivar Wi-Fi. | Las lecturas se guardan en `localStorage` (indicador offline). Al volver online, se sincronizan solas sin fallar. | [ ] |
| **ESC-04** | Sesión Expirada (401/403) | Simular expiración de sesión (borrar cookies o nonce inválido). | Se muestra alerta modal indicando que la sesión expiró y solicitando re-loguearse. Los SKUs no se borran del DOM. | [ ] |

---

## 3. Pruebas del Flujo de Estados

| ID | Caso de Prueba | Entrada / Acción | Resultado Esperado | Estado |
|---|---|---|---|---|
| **FE-01** | Estado Inicial (Borrador) | Crear un lote nuevo. | Se guarda con estado `draft`. El escáner está activo para recibir conteos. | [ ] |
| **FE-02** | Transición a Pendiente de Precios | Cambiar estado a `mm_p_precios` y guardar. | El escáner se bloquea. Se habilita la tabla financiera para ingresar costos y precios. | [ ] |
| **FE-03** | Transición a Pendiente de Aprobación | Guardar precios/costos y cambiar estado a `mm_p_aprobacion`. | Los campos financieros se bloquean para el Ingresador. El lote queda en espera de revisión. | [ ] |
| **FE-04** | Aprobación Final | Cambiar a `mm_cargado` por Administrador. | El lote queda bloqueado permanentemente contra modificaciones de cualquier tipo. | [ ] |

---

## 4. Pruebas Financieras e Integración de IA

| ID | Caso de Prueba | Entrada / Acción | Resultado Esperado | Estado |
|---|---|---|---|---|
| **FIN-01** | Guardado de Costos y Precios | Ingresar costos de $10 y precios de $15 en la tabla financiera. | Se guarda en `wp_mm_lote_items`. El margen proyectado debe calcularse correctamente en `33.3%`. | [ ] |
| **FIN-02** | Asistente de Precios por IA | Hacer clic en "Sugerir precios por IA". | Rellena automáticamente los campos vacíos con precios coherentes basados en los costos y el margen global configurado. | [ ] |

---

## 5. Pruebas de Sincronización Asíncrona (Action Scheduler)

| ID | Caso de Prueba | Entrada / Acción | Resultado Esperado | Estado |
|---|---|---|---|---|
| **SYNC-01** | Disparo de Cola Asíncrona | Pasar lote a `mm_cargado`. | Se agenda la acción `mm_logistica_sync_lote_chunk` en el Action Scheduler. | [ ] |
| **SYNC-02** | Procesamiento por Bloques (Lote > 50) | Guardar lote con 60 ítems y aprobar. | Se procesa el primer bloque de 50 ítems, y se agenda automáticamente el siguiente bloque de 10 ítems. | [ ] |
| **SYNC-03** | Verificación de Stock en WC | Buscar productos sincronizados en WooCommerce. | El stock se actualiza correctamente (suma o reemplaza) y se guardan los precios de venta y costos. | [ ] |
| **SYNC-04** | Resiliencia de Sync | Interrumpir proceso a mitad (ej. caída de servidor) y reanudar. | Continúa desde el primer producto con `synced_at` en NULL. **No se duplica inventario** de ítems ya sincronizados. | [ ] |
| **SYNC-05** | Prevención de Doble Ejecución | Intentar sincronizar lote ya procesado (`_sincronizado_wc` = 1). | El sistema bloquea el re-procesamiento protegiendo el stock de WooCommerce. | [ ] |

---

## 6. Pruebas de Impresión y Exportación

| ID | Caso de Prueba | Entrada / Acción | Resultado Esperado | Estado |
|---|---|---|---|---|
| **IMP-01** | Renderizado de Tickets | Hacer clic en "Imprimir Etiquetas". | Abre ventana limpia, sin elementos del panel de administración de WordPress. Cada etiqueta tiene salto de página. | [ ] |
| **IMP-02** | Códigos de Barra sin Internet | Cargar vista de ticket desconectado de red. | Los códigos de barra lineales se dibujan localmente en pantalla de inmediato gracias al JS local. | [ ] |
| **EXP-01** | Descarga de CSV | Hacer clic en "Exportar Lote a CSV". | Se descarga un archivo `.csv` válido conteniendo todas las columnas de la auditoría física y financiera. | [ ] |

---

## 7. Pruebas de Entorno y Desactivación

| ID | Caso de Prueba | Entrada / Acción | Resultado Esperado | Estado |
|---|---|---|---|---|
| **ENV-01** | Desactivar WooCommerce | Desactivar WooCommerce temporalmente en Ajustes. | El plugin MegaMundo Logística se comporta de forma segura: no genera errores fatales de compilación PHP. | [ ] |
