# README Técnico - MegaMundo Logística

Este documento contiene la especificación técnica completa y la guía de arquitectura del plugin **MegaMundo Logística**.

---

## A. Arquitectura del Software

El plugin ha sido estructurado bajo los estándares modernos de desarrollo de WordPress, utilizando programación orientada a objetos (OOP), namespaces estructurados bajo el estándar **PSR-4**, e inyección de dependencias mediante un contenedor de servicios.

### 1. Autoloading PSR-4
- El namespace raíz es `MegaMundo\Logistica`.
- El autoloader carga dinámicamente las clases desde la carpeta `src/`.
- El punto de entrada `megamundo-logistica.php` registra el autoloader personalizado para resolver las dependencias sin requerir llamadas manuales a `require_once`.

### 2. Contenedor de Inyección de Dependencias (DI Container)
- La clase `MegaMundo\Logistica\Core\Container` gestiona la creación de instancias de servicios, inyectando de forma limpia las dependencias de base de datos, configuración y lógica de negocio.
- Esto permite la modularidad del sistema y facilita la escritura de pruebas unitarias/integración.

---

## B. Estructura de Base de Datos

El plugin utiliza una tabla personalizada en la base de datos de WordPress para almacenar de manera estructurada los ítems del lote de forma transaccional, evitando sobrecargar la tabla nativa `wp_postmeta`.

### Tabla: `wp_mm_lote_items`
```sql
CREATE TABLE wp_mm_lote_items (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    lote_id BIGINT(20) UNSIGNED NOT NULL,
    sku VARCHAR(100) NOT NULL,
    cantidad INT(11) NOT NULL DEFAULT 0,
    tipo_movimiento VARCHAR(20) NOT NULL DEFAULT 'sumar',
    costo DECIMAL(10,2) NULL,
    precio DECIMAL(10,2) NULL,
    created_by BIGINT(20) UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    synced_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY lote_id (lote_id),
    KEY sku (sku),
    KEY synced_at (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- **Indices**:
  - `lote_id`: Acelera la carga de productos asociados a un lote específico.
  - `sku`: Permite la búsqueda indexada del SKU durante el escaneo y la sincronización.
  - `synced_at`: Facilita la identificación de productos pendientes de procesamiento.

---

## C. Ciclo de Vida y Seguridad de la REST API

Los endpoints de comunicación para el escáner móvil se registran a través de la REST API nativa de WordPress.

### Endpoints Registrados (Namespace: `megamundo-logistica/v1`)
- `GET /lote/(?P<id>\d+)/items`: Recupera los ítems del lote para renderizar el listado en el escáner.
- `POST /lote/(?P<id>\d+)/scan`: Registra un escaneo físico (SKU y cantidad).
- `POST /lote/(?P<id>\d+)/save`: Guarda la tabla de conteo actual.

### Mecanismos de Seguridad
1. **Validación de Capacidad (`permission_callback`)**:
   - Solo usuarios con la capacidad `mm_contar_lotes` o superior (`administrator`) pueden acceder a los endpoints.
2. **Validación de Nonce**:
   - Todas las llamadas de tipo mutación (`POST`) requieren el encabezado `X-WP-Nonce`. Si el nonce ha expirado o es inválido, la API devuelve un código de estado `403 Forbidden`.

---

## D. Lógica del Escáner Frontend y Backend

### Frontend: `assets/js/app-bodega.js`
- El escáner utiliza JavaScript vainilla para el manejo reactivo del DOM y la comunicación con el servidor.
- **Cola Offline (`localStorage`)**:
  - Cada vez que el operario escanea un producto, se intenta realizar la llamada REST `POST /scan`.
  - Si la petición falla por problemas de conectividad (estado de red fallido o sin conexión), el objeto se guarda en una cola local (`localStorage.getItem('mm_offline_scans')`).
  - Un intervalo periódico en segundo plano verifica si hay conexión; si detecta conectividad, vacía la cola y sincroniza los datos pendientes de forma secuencial.
- **Manejo de Errores de Autenticación (401/403)**:
  - Si el servidor responde con un código `401 Unauthorized` o `403 Forbidden`, la aplicación detiene el procesamiento, bloquea el envío de datos y muestra una alerta modal clara: **"Tu sesión expiró. Por favor vuelve a iniciar sesión para continuar escaneando."**.
  - Evita que los datos locales se pierdan, manteniendo la tabla visual congelada en pantalla para que el operario no pierda el progreso de su conteo físico.

---

## E. Sincronización Asíncrona (Action Scheduler)

Para procesar grandes volúmenes de productos por lote sin generar timeouts o bloqueos del servidor PHP, se utiliza la cola nativa de WooCommerce: **Action Scheduler**.

### Ciclo de Procesamiento
1. Cuando un lote pasa al estado `mm_cargado`, se dispara la acción de inicialización de sincronización:
   - Hook: `mm_logistica_sync_lote_chunk`
2. El procesador asíncrono (`SyncProcessor.php`) lee los ítems del lote en la base de datos `wp_mm_lote_items` que tengan `synced_at IS NULL`.
3. Procesa un **bloque de un tamaño definido (por defecto: 50 productos)**.
4. Para cada ítem:
   - Valida la existencia del producto en WooCommerce.
   - Aplica el ajuste de stock (suma al inventario existente o lo reemplaza) en la base de datos y activa la meta del post.
   - Sincroniza el precio (`_regular_price`) y costo (`_purchase_price` de WooCommerce Product Cost).
   - Registra el timestamp en `synced_at` en `wp_mm_lote_items`.
5. Si quedan ítems por sincronizar en el lote, se programa de inmediato el siguiente bloque mediante `as_schedule_single_action`.
6. Si todos los ítems han sido procesados, se dispara la acción de cierre `mm_logistica_finish_lote_sync` que cambia el estado interno del lote meta a `_sincronizado_wc = 1`, protegiendo al lote contra sincronizaciones duplicadas accidentales.

---

## F. Sistema de Impresión y Generación de Códigos de Barras

La impresión de etiquetas térmicas de código de barras se realiza de forma 100% cliente para garantizar el funcionamiento sin internet estable en el sector de bodega.

1. **Carga Local de JsBarcode**:
   - Para no depender de CDNs externas de alta latencia o propensas a caídas, se ha integrado localmente en `assets/vendor/jsbarcode/JsBarcode.all.min.js`.
2. **Generación**:
   - `TicketPrintView.php` renderiza elementos `<svg class="codigo-barras" data-value="SKU"></svg>`.
   - Al cargarse el DOM, se invoca `JsBarcode(".codigo-barras").init()`, dibujando instantáneamente los códigos lineales en formato CODE128 o EAN.
3. **CSS Print**:
   - Estilos dedicados de impresión remueven cabeceras de WordPress, menús laterales, y fuerzan saltos de página (`page-break-after: always`) por cada etiqueta para ajustarse al formato de la ticketera térmica.

---

## G. Estructura de Clases y Responsabilidades

El plugin sigue una distribución limpia de carpetas bajo `src/`:

- **`Core/`**:
  - `Bootstrap.php`: Inicialización del plugin, registro de hooks globales, y encolado de estilos administrativos.
  - `Container.php`: Contenedor de Inyección de Dependencias sencillo y veloz.
- **`Database/`**:
  - `DatabaseInstaller.php`: Instalación de tablas del plugin y control de versiones del esquema SQL.
  - `LoteItemsRepository.php`: Abstracción de acceso a la tabla `wp_mm_lote_items` mediante `$wpdb`.
- **`Presentation/`**:
  - **`Admin/`**:
    - `MetaboxController.php`: Renderizado y lógica de guardado de los metaboxes del lote.
    - `SettingsController.php`: Pantalla de ajustes de administración del plugin.
  - **`Api/`**:
    - `RestApiController.php`: Registro de endpoints y validación de tokens de sesión REST.
  - **`Front/`**:
    - `ScannerViewController.php`: Carga y renderizado del escáner en bodega por medio de shortcode.
  - **`Print/`**:
    - `TicketPrintView.php`: Vista optimizada para la impresión térmica de etiquetas.
- **`Infrastructure/`**:
  - **`OpenAI/`**:
    - `OpenAiVisionService.php`: Comunicación con la API de Inteligencia Artificial para la estimación inteligente de precios y análisis de facturas.
  - **`Roles/`**:
    - `RoleManager.php`: Creación y remoción de capacidades para operarios de bodega (`mm_contador`) e ingresadores (`mm_ingresador`).
  - **`Sync/`**:
    - `SyncProcessor.php`: Lógica central de inyección de inventario y actualización de WooCommerce.
    - `SyncScheduler.php`: Programador y controlador de colas del Action Scheduler.

---

## H. Flujo de Estados de CPT y Hooks

El CPT `lotes_ingreso` utiliza las transiciones de posts nativas de WordPress (`transition_post_status`) para disparar eventos:
- Cuando el post pasa de cualquier estado a `mm_cargado`, el callback en `MetaboxController` captura el evento e inicializa la cola asíncrona.
- Si el lote se intenta guardar de nuevo, la metakey `_sincronizado_wc` impide volver a programar la cola, asegurando la idempotencia del sistema.

---

## I. Seguridad Aplicada

1. **WooCommerce Activo**:
   - En `megamundo-logistica.php` se valida la presencia y activación de WooCommerce antes de registrar cualquier controlador o cargar servicios que invoquen funciones del eCommerce.
2. **Sanitización de Datos**:
   - Todos los inputs recibidos por HTTP REST o vía POST clásico en la administración se procesan con `sanitize_text_field()`, `sanitize_key()`, o `absint()` según su tipo.
3. **Escapado de Outputs**:
   - Cada dato dinámico impreso en las vistas se escapa con funciones de WordPress (`esc_html()`, `esc_attr()`, `esc_url()`).
4. **Verificación de Capacidades (Capabilities)**:
   - `mm_ingresador` y `administrator` controlan los campos financieros.
   - `mm_contador` tiene denegado el acceso a la tabla financiera tanto a nivel de DOM como a nivel de endpoints REST.

---

## J. Rendimiento y Optimización

- **Carga Diferida**: Las dependencias no se instancian hasta que el contenedor de servicios las requiere, optimizando el tiempo de respuesta general de WordPress en frontend.
- **Consultas Optimizadas**: Consultas directas mediante `$wpdb` para la inserción y listado de productos de lotes evitan la hidratación lenta de objetos WP_Post.
- **Action Scheduler**: Divide la carga en bloques ligeros (por defecto 50), evitando el agotamiento de memoria del hosting (memory exhaustion).

---

## K. Requisitos del Sistema

- **PHP**: 8.0 o superior (Estricto).
- **WordPress**: 5.8 o superior.
- **WooCommerce**: 6.0 o superior.
- **Base de Datos**: MySQL 5.7 o superior / MariaDB 10.3 o superior (Soporte InnoDB).

---

## L. Instrucciones de Instalación

1. Subir la carpeta `megamundo-logistica` al directorio `/wp-content/plugins/`.
2. Activar el plugin desde la sección **Plugins** en la administración de WordPress.
3. El instalador ejecutará la creación de la tabla `wp_mm_lote_items` y registrará los roles logísticos automáticamente.
4. Ir a **MegaMundo > Ajustes** e ingresar la clave de la API de OpenAI si se desea utilizar la IA.

---

## M. Guía de Desarrollo y Extensión

### Agregar un Nuevo Estado al Lote
1. Registre el nuevo estado en `MetaboxController.php` dentro del array de estados del lote.
2. Defina su comportamiento en el flujo lógico de transiciones.
3. Actualice el CSS en `assets/css/admin.css` para darle color al estado del lote en la tabla de listado.

### Agregar un Nuevo Endpoint REST
1. Defina la ruta y el método en `RestApiController.php`.
2. Cree el callback correspondiente para procesar la lógica de negocio y verifique los permisos en el callback de autorización.

---

## N. Registro y Logs

Los eventos de sincronización del Action Scheduler se registran en los logs nativos de WooCommerce (`WC_Logger`) y en la cola interna de Action Scheduler. Esto permite la trazabilidad completa en caso de SKUs conflictivos o errores de inventario.

---

## O. Integración con IA

La clase `OpenAiVisionService.php` utiliza la API de OpenAI para procesar datos complejos:
- **Sugerencia de Precios**: Lee los costos ingresados y propone un precio de venta optimizado basado en el margen global de ajustes.
- **Lectura de Facturas**: Soporte para la extracción automática de SKUs y cantidades a partir de imágenes de facturas de proveedores.

---

## P. Pruebas de Consistencia e Integración

Antes de entregar a producción, verifique:
1. **Lote Pequeño**: Escanee 5 ítems de prueba, realice el flujo completo y confirme que se actualizan stock y precios correctamente.
2. **Lote Mediano (Offline)**: Escanee ítems con conexión desactivada, reactive la red y verifique la sincronización en lote.
3. **Lote Grande (> 100 ítems)**: Ejecute el procesamiento y valide en **Cola de Acciones** que se procesa en bloques asíncronos sucesivos.

---

## Q. Plan de Despliegue en Staging y Producción

### 1. Entorno de Staging (Hostinger)
- **URL**: `darkcyan-crane-982046.hostingersite.com`
- **Pasos de Despliegue**:
  1. Realizar copia de seguridad de la base de datos actual de Staging.
  2. Subir el archivo empaquetado `megamundo-logistica.zip` a través del cargador de plugins de WordPress o vía SFTP.
  3. Activar el plugin.
  4. Ejecutar el checklist de QA (`CHECKLIST-QA.md`).
  5. Validar que no se generen advertencias en `wp-content/debug.log`.

### 2. Entorno de Producción
- Una vez certificado el correcto funcionamiento en Staging con cero errores:
  1. Programar ventana de mantenimiento (de preferencia en horarios de bajo tráfico logístico).
  2. Activar modo mantenimiento en WordPress.
  3. Realizar backup completo (Archivos + DB).
  4. Subir la versión `2.0.0` del plugin.
  5. Activar y verificar la inyección de stock de prueba inicial.
  6. Desactivar modo mantenimiento.

---

## R. Flujo de Desarrollo (desde Fase 1 de refactoring, junio 2026)

### Estructura de la capa de presentación frontend
`ScannerViewController` actúa como shell de la app (routing por `?mm_logistica_app=`, sidebar, login, dispatch por rol). La lógica de cada dominio vive en traits bajo `src/Presentation/Front/Concerns/`:

| Trait | Dominio |
|---|---|
| `FacturasTrait` | Carga, análisis IA y gestión de facturas de lote |
| `PedidosTrait` | Pedidos de compra y creación de lotes desde pedido |
| `BodegaTrait` | Recepción, validación y paneles de bodega |
| `PreciosTrait` | Precios simples y múltiples, envío a aprobación |
| `ExhibicionTrait`, `EtiquetasTrait`, `JefaturaTrait`, `MekanoTrait`, `ConfigIaTrait`, `NotificacionesTrait`, `ReportesTrait`, `SincronizacionTrait`, `HistorialTrait`, `UsuariosTrait`, `SistemaTrait`, `ProductosNuevosTrait`, `ProductosSinImagenTrait`, `LoteDetailTrait` | Un dominio por trait |

Los traits son el paso intermedio: cada uno define la frontera de un futuro controlador con servicios inyectados. Al graduar un trait a controlador, hacerlo de a uno por PR con el CI en verde.

### Tests y CI
- `composer install` instala PHPUnit (requiere PHP >= 7.4 en la máquina de desarrollo).
- `composer test` ejecuta la suite unitaria (`tests/Unit/`, con shims de WordPress en `tests/bootstrap.php` — no requiere WordPress).
- `composer lint` valida sintaxis de todos los archivos PHP.
- GitHub Actions (`.github/workflows/ci.yml`) ejecuta lint en PHP 7.4 y 8.2 + PHPUnit en cada push y pull request.
