# Auditoría de seguridad — Handlers AJAX

**Fecha:** 2026-06-13 · **Alcance:** los 32 handlers AJAX del plugin (30 frontend en `src/Presentation/Front/`, 2 admin en `MetaboxController`).

## Criterio

Cada handler AJAX debe verificar, antes de mutar datos:
1. **Nonce** (`wp_verify_nonce` / `check_ajax_referer`) — contra CSRF.
2. **Capacidad** (`current_user_can`, `PermissionGuard`, o helper de rol) — para que un usuario autenticado de rol bajo no ejecute acciones que no le corresponden.

Todos se registran con `wp_ajax_*` (no `wp_ajax_nopriv_*`), es decir, ya exigen sesión iniciada.

## Resultado: ✅ sin hallazgos abiertos

Todos los handlers verifican nonce y capacidad. Casos especiales justificados:

| Handler | Nonce | Capacidad | Nota |
|---|---|---|---|
| `ajax_marcar_notificacion_vista_app` | ✅ | login | Solo escribe en el `user_meta` del propio usuario (`get_current_user_id`). Login + nonce es suficiente. |
| `ajax_finalizar_bodega_lote` | — | — | Delega en `ajax_guardar_bodega_receipt_no_exit`, que verifica `can_access_bodega_panel` + nonce. |
| `ajax_guardar_precios_app`, `ajax_enviar_aprobacion_app` | ✅ | `user_can_edit_prices_in_app` → `can_edit_finance`/`is_admin` | Verificación de rol vía helper, además de validar estado del lote. |

El resto verifica explícitamente `can_access_{bodega,precios,jefatura}_panel`, `can_edit_finance`, `can_add_lote_comment`, `can_update_lote_checklist` o equivalente, junto con su nonce específico por acción.

## Endurecimiento aplicado en esta línea de trabajo

Commit `91bb81d` añadió verificación de capacidad + nonce a tres handlers que antes solo comprobaban `is_user_logged_in`:
- `ajax_guardar_bodega_receipt` y `ajax_guardar_bodega_receipt_no_exit` → `can_access_bodega_panel`
- `ajax_analizar_factura_ia` → rol de panel + nonce `mm_factura_ia`
- `ajax_resolver_producto_sin_imagen` → rol de panel + nonce nuevo `mm_resolver_producto_sin_imagen`

## Cómo reauditar

```bash
# Lista handlers y marca si su cuerpo menciona nonce y capacidad
grep -rn "function ajax_" src/Presentation/
```
Revisar manualmente que cada uno verifique ambos antes de la primera mutación. Mantener esta tabla al añadir handlers nuevos.
