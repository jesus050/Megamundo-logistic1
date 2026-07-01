/* MegaMundo — Módulo Ubicaciones e Inventario Físico */
(function () {
    'use strict';

    function ajax(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); });
    }

    function showMsg(box, text, isError) {
        if (!box) return;
        box.hidden = false;
        box.textContent = text;
        box.className = box.className.replace(/\bis-(ok|error)\b/g, '').trim() + (isError ? ' is-error' : ' is-ok');
    }

    /* ---------- Conteo físico: cálculo en vivo + guardar ---------- */
    var countForm = document.querySelector('.mm-inv-count-form');
    if (countForm) {
        var cantidadEl = countForm.querySelector('.mm-inv-cantidad');
        var presentEl = countForm.querySelector('.mm-inv-presentacion');
        var totalEl = countForm.querySelector('.mm-inv-total-unidades');
        var skuEl = countForm.querySelector('.mm-inv-sku');
        var sessionList = document.querySelector('.mm-inv-session-list');

        function recalcTotal() {
            var cant = parseInt(cantidadEl.value, 10) || 0;
            var opt = presentEl.options[presentEl.selectedIndex];
            var equiv = opt ? (parseInt(opt.dataset.unidades, 10) || 1) : 1;
            if (totalEl) totalEl.textContent = cant * equiv;
        }
        if (cantidadEl) cantidadEl.addEventListener('input', recalcTotal);
        if (presentEl) presentEl.addEventListener('change', recalcTotal);
        recalcTotal();

        countForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = countForm.querySelector('.mm-inv-count-msg');
            var btn = countForm.querySelector('.mm-inv-btn-guardar');
            var payload = {
                nonce: countForm.querySelector('[name="nonce"]').value,
                ubicacion_id: countForm.querySelector('[name="ubicacion_id"]').value,
                sku: countForm.querySelector('[name="sku"]').value,
                nombre_producto: countForm.querySelector('[name="nombre_producto"]').value,
                categoria: countForm.querySelector('[name="categoria"]').value,
                cantidad: cantidadEl.value,
                presentacion: presentEl.value,
                observacion: countForm.querySelector('[name="observacion"]').value
            };
            if (btn) btn.disabled = true;
            ajax('mm_app_guardar_conteo', payload).then(function (r) {
                if (!r.ok || !r.json.success) {
                    showMsg(msg, (r.json.data && r.json.data.message) || 'No se pudo guardar.', true);
                    return;
                }
                showMsg(msg, r.json.data.message, false);
                // Agregar a la lista de la sesión (arriba).
                if (sessionList) {
                    var empty = sessionList.querySelector('.mm-empty-state');
                    if (empty) empty.remove();
                    var row = document.createElement('div');
                    row.className = 'mm-inv-session-item';
                    var label = (payload.sku || payload.nombre_producto || 'Producto');
                    row.innerHTML = '<strong>' + escapeHtml(label) + '</strong><span>' + r.json.data.total_unidades + ' uds</span>';
                    sessionList.insertBefore(row, sessionList.firstChild);
                }
                // Siguiente producto: limpiar código/nombre y reenfocar el escáner.
                countForm.querySelector('[name="sku"]').value = '';
                countForm.querySelector('[name="nombre_producto"]').value = '';
                countForm.querySelector('[name="categoria"]').value = '';
                countForm.querySelector('[name="observacion"]').value = '';
                cantidadEl.value = 1;
                recalcTotal();
                if (skuEl) skuEl.focus();
            }).finally(function () { if (btn) btn.disabled = false; });
        });

        // Lector tipo teclado: Enter en el campo SKU no envía el form, salta a cantidad.
        if (skuEl) {
            skuEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); if (cantidadEl) { cantidadEl.focus(); cantidadEl.select(); } }
            });
        }
    }

    /* ---------- Ubicaciones: auto-armar código + guardar + eliminar ---------- */
    var ubicForm = document.querySelector('.mm-ubicacion-form');
    if (ubicForm) {
        var codigoEl = ubicForm.querySelector('.mm-ubic-codigo');
        var partEls = Array.prototype.slice.call(ubicForm.querySelectorAll('.mm-ubic-part'));
        var codigoEditado = false;
        if (codigoEl) codigoEl.addEventListener('input', function () { codigoEditado = true; });

        function armarCodigo() {
            if (codigoEditado) return;
            var partes = partEls.map(function (el) { return (el.value || '').trim().toUpperCase(); }).filter(Boolean);
            if (codigoEl) codigoEl.value = partes.join('-');
        }
        partEls.forEach(function (el) { el.addEventListener('input', armarCodigo); });

        ubicForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = ubicForm.querySelector('.mm-ubicacion-msg');
            var payload = {};
            Array.prototype.slice.call(ubicForm.querySelectorAll('[name]')).forEach(function (el) { payload[el.name] = el.value; });
            ajax('mm_app_guardar_ubicacion', payload).then(function (r) {
                if (!r.ok || !r.json.success) {
                    showMsg(msg, (r.json.data && r.json.data.message) || 'No se pudo guardar.', true);
                    return;
                }
                showMsg(msg, r.json.data.message + ' Recargando…', false);
                setTimeout(function () { location.reload(); }, 700);
            });
        });
    }

    /* ---------- Equivalencias: guardar ---------- */
    var equivForm = document.querySelector('.mm-equivalencia-form');
    if (equivForm) {
        equivForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = equivForm.querySelector('.mm-equivalencia-msg');
            var payload = {};
            Array.prototype.slice.call(equivForm.querySelectorAll('[name]')).forEach(function (el) { payload[el.name] = el.value; });
            ajax('mm_app_guardar_equivalencia', payload).then(function (r) {
                if (!r.ok || !r.json.success) {
                    showMsg(msg, (r.json.data && r.json.data.message) || 'No se pudo guardar.', true);
                    return;
                }
                showMsg(msg, r.json.data.message + ' Recargando…', false);
                setTimeout(function () { location.reload(); }, 700);
            });
        });
    }

    /* ---------- Botones eliminar (ubicación / equivalencia / conteo) ---------- */
    function bindDelete(selector, action, confirmText) {
        document.querySelectorAll(selector).forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm(confirmText)) return;
                ajax(action, { id: btn.dataset.id, nonce: btn.dataset.nonce }).then(function (r) {
                    if (!r.ok || !r.json.success) {
                        window.alert((r.json.data && r.json.data.message) || 'No se pudo eliminar.');
                        return;
                    }
                    var tr = btn.closest('tr');
                    if (tr) tr.remove();
                });
            });
        });
    }
    bindDelete('.mm-ubicacion-delete', 'mm_app_eliminar_ubicacion', '¿Eliminar esta ubicación?');
    bindDelete('.mm-equivalencia-delete', 'mm_app_eliminar_equivalencia', '¿Eliminar esta equivalencia?');
    bindDelete('.mm-conteo-delete', 'mm_app_eliminar_conteo', '¿Eliminar este conteo?');

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
