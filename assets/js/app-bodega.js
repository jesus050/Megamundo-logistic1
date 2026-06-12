(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('mm-form-escaner');
        if (!form) return;

        const inputCodigo = document.getElementById('mm-codigo-producto');
        const inputCantidad = document.getElementById('mm-cantidad');
        const inputLote = document.getElementById('mm-lote-id');
        const divMensaje = document.getElementById('mm-mensaje-estado');
        const cajaNuevo = document.getElementById('mm-caja-nombre-nuevo');
        const inputNombreNuevo = document.getElementById('mm-nombre-nuevo');
        const inputFoto = document.getElementById('mm-foto-nuevo');
        const inputFotoArchivo = document.getElementById('mm-foto-archivo');
        const btnTomarFoto = document.getElementById('mm-btn-tomar-foto');
        const btnBuscarFoto = document.getElementById('mm-btn-buscar-foto');
        const inputCategoriaIA = document.getElementById('mm-categoria-ia');
        const inputPalabrasIA = document.getElementById('mm-palabras-clave-ia');
        const inputDescripcionIA = document.getElementById('mm-descripcion-ia');
        const estadoIA = document.getElementById('mm-ia-estado');
        const btnAnalizarIA = document.getElementById('mm-btn-analizar-ia');
        const previewFoto = document.getElementById('mm-foto-preview');
        const divContador = document.getElementById('mm-pendientes-contador');
        const divEstadoConex = document.getElementById('mm-estado-conexion');
        const ultimos = document.getElementById('mm-ultimos-escaneos');
        const btnNuevo = document.getElementById('mm-btn-producto-nuevo');
        const btnCamara = document.getElementById('mm-btn-scan-camera');
        const dotOnline = document.getElementById('mm-ui-online-dot');
        const textOnline = document.getElementById('mm-ui-online-text');
        const miniPending = document.getElementById('mm-ui-pending-mini');
        const cameraModal = document.getElementById('mm-camera-modal');
        const cameraVideo = document.getElementById('mm-camera-video');
        const closeCamera = document.getElementById('mm-close-camera');
        const cameraHelp = document.getElementById('mm-camera-help');

        const QUEUE_KEY = 'mm_offline_scans';
        let estaSincronizando = false;
        let localIdsEnProgreso = new Set();
        let stream = null;
        let detectorTimer = null;

        function apiUrl() {
            return (window.mmApiSettings && mmApiSettings.root)
                ? mmApiSettings.root.replace(/\/$/, '/') + 'megamundo/v1/escaner/agregar'
                : '/wp-json/megamundo/v1/escaner/agregar';
        }

        function apiHeaders() {
            return (window.mmApiSettings && mmApiSettings.nonce)
                ? { 'X-WP-Nonce': mmApiSettings.nonce }
                : {};
        }

        function apiUrlIAProducto() {
            return (window.mmApiSettings && mmApiSettings.root)
                ? mmApiSettings.root.replace(/\/$/, '/') + 'megamundo/v1/ia/analizar-producto'
                : '/wp-json/megamundo/v1/ia/analizar-producto';
        }

        function mostrarMensaje(texto, tipo) {
            if (!divMensaje) return;
            divMensaje.innerHTML = texto || '';
            divMensaje.className = 'mm-message';
            if (tipo) divMensaje.classList.add('mm-message-' + tipo);
            divMensaje.hidden = !texto;
        }

        function agregarMovimiento(texto, tipo) {
            if (!ultimos) return;
            const empty = ultimos.querySelector('.is-empty');
            if (empty) empty.remove();
            const li = document.createElement('li');
            li.className = tipo || 'info';
            li.innerHTML = `<strong>${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</strong><span>${texto}</span>`;
            ultimos.prepend(li);
            while (ultimos.children.length > 8) ultimos.removeChild(ultimos.lastElementChild);
        }

        function abrirProductoNuevo(mensaje) {
            if (!cajaNuevo) return;
            cajaNuevo.hidden = false;
            cajaNuevo.classList.add('is-open');
            if (mensaje) mostrarMensaje(mensaje, 'warning');
            setTimeout(function () {
                if (inputNombreNuevo) inputNombreNuevo.focus();
                cajaNuevo.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 120);
        }

        function cerrarProductoNuevo() {
            if (!cajaNuevo) return;
            cajaNuevo.hidden = true;
            cajaNuevo.classList.remove('is-open');
            if (inputNombreNuevo) inputNombreNuevo.value = '';
            if (inputFoto) inputFoto.value = '';
            if (inputFotoArchivo) inputFotoArchivo.value = '';
            if (previewFoto) {
                previewFoto.hidden = true;
                previewFoto.innerHTML = '';
            }
        }

        function validarLoteSeleccionado() {
            const loteId = inputLote ? inputLote.value : '';
            const btnSubmit = document.getElementById('mm-btn-submit');
            const disabled = !loteId;

            if (inputCodigo) inputCodigo.disabled = disabled;
            if (inputCantidad) inputCantidad.disabled = disabled;
            if (btnSubmit) btnSubmit.disabled = disabled;
            if (btnCamara) btnCamara.disabled = disabled;
            if (btnNuevo) btnNuevo.disabled = disabled;

            if (disabled) {
                mostrarMensaje('Selecciona un lote activo para comenzar a escanear.', 'warning');
                return false;
            }
            return true;
        }

        function obtenerColaOffline() {
            try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }
            catch (e) { return []; }
        }

        function guardarColaOffline(queue) {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
            actualizarContadorUI();
        }

        function agregarAColaOffline(scan) {
            const queue = obtenerColaOffline();
            queue.push(scan);
            guardarColaOffline(queue);
        }

        function eliminarDeColaOffline(localId) {
            guardarColaOffline(obtenerColaOffline().filter(item => item.local_id !== localId));
        }

        function actualizarContadorUI() {
            const count = obtenerColaOffline().length;
            if (miniPending) miniPending.textContent = String(count);
            if (!divContador) return;
            if (count > 0) {
                divContador.hidden = false;
                divContador.innerHTML = `⏳ Pendientes por sincronizar: <strong>${count}</strong>`;
            } else {
                divContador.hidden = true;
                divContador.innerHTML = '';
            }
        }

        function actualizarEstadoConexionUI() {
            const online = navigator.onLine;
            if (dotOnline) dotOnline.className = 'mm-dot ' + (online ? 'mm-dot-online' : 'mm-dot-offline');
            if (textOnline) textOnline.textContent = online ? 'Online' : 'Offline';
            if (!divEstadoConex) return;
            if (online) {
                divEstadoConex.hidden = true;
                divEstadoConex.innerHTML = '';
            } else {
                divEstadoConex.hidden = false;
                divEstadoConex.innerHTML = '🔌 Modo offline: los SKUs existentes se guardarán localmente hasta recuperar conexión.';
            }
        }

        async function leerJsonSeguro(response) {
            const text = await response.text();
            try { return text ? JSON.parse(text) : {}; }
            catch (e) { return { code: 'respuesta_invalida', message: text || 'Respuesta inválida del servidor.' }; }
        }

        async function enviarFormData(formData) {
            const response = await fetch(apiUrl(), {
                method: 'POST',
                headers: apiHeaders(),
                body: formData,
                credentials: 'same-origin'
            });
            const data = await leerJsonSeguro(response);
            return { response, data };
        }

        function construirFormData(payload) {
            const fd = new FormData();
            fd.append('lote_id', payload.lote_id);
            fd.append('sku_producto', payload.sku_producto);
            fd.append('cantidad', payload.cantidad || '1');
            if (payload.nombre_nuevo) fd.append('nombre_nuevo', payload.nombre_nuevo);
            if (payload.foto_producto) fd.append('foto_producto', payload.foto_producto);
            return fd;
        }

        async function procesarRespuesta(response, data, contexto) {
            if (response.status === 401 || response.status === 403 || data.code === 'rest_forbidden') {
                mostrarMensaje("Tu sesión expiró o no tienes permiso. <a href='#' onclick='window.location.reload();return false;'>Recargar / iniciar sesión</a>", 'error');
                return false;
            }

            if (data.code === 'requiere_nombre' || response.status === 404 && data.code === 'requiere_nombre') {
                abrirProductoNuevo('Este SKU no existe. Completa el nombre y toma una foto para crearlo.');
                return false;
            }

            if (!response.ok || data.success === false) {
                const msg = data.message || data.mensaje || 'No se pudo guardar el escaneo. Intenta nuevamente.';
                mostrarMensaje(msg, 'error');
                agregarMovimiento(msg, 'error');
                return false;
            }

            const mensaje = data.mensaje || data.message || 'Escaneo guardado correctamente.';
            mostrarMensaje(mensaje, 'success');
            agregarMovimiento(mensaje, 'success');
            cerrarProductoNuevo();
            if (inputCodigo) inputCodigo.value = '';
            if (inputCantidad) inputCantidad.value = '1';
            if (inputCodigo) inputCodigo.focus();
            return true;
        }

        async function sincronizarColaOffline() {
            if (estaSincronizando || !navigator.onLine) return;
            const queue = obtenerColaOffline();
            if (queue.length === 0) return;

            estaSincronizando = true;
            mostrarMensaje(`Sincronizando ${queue.length} escaneo(s) pendiente(s)...`, 'processing');

            for (const scan of queue) {
                if (localIdsEnProgreso.has(scan.local_id)) continue;
                localIdsEnProgreso.add(scan.local_id);
                try {
                    const { response, data } = await enviarFormData(construirFormData(scan));
                    if (response.ok && data.success) {
                        eliminarDeColaOffline(scan.local_id);
                        agregarMovimiento(`SKU ${scan.sku_producto} sincronizado desde cola offline.`, 'success');
                    }
                } catch (e) {
                    break;
                } finally {
                    localIdsEnProgreso.delete(scan.local_id);
                }
            }

            estaSincronizando = false;
            actualizarContadorUI();
            if (obtenerColaOffline().length === 0) {
                mostrarMensaje('Escaneos pendientes sincronizados correctamente.', 'success');
            }
        }

        async function manejarSubmit(e) {
            e.preventDefault();
            if (!validarLoteSeleccionado()) return;

            const loteId = inputLote.value;
            const sku = (inputCodigo.value || '').trim();
            const cantidad = inputCantidad.value || '1';
            const nombreNuevo = inputNombreNuevo ? inputNombreNuevo.value.trim() : '';
            const foto = obtenerFotoSeleccionada();
            const esFormularioNuevoVisible = cajaNuevo && !cajaNuevo.hidden;

            if (!sku) {
                mostrarMensaje('Debes ingresar o escanear un SKU.', 'warning');
                if (inputCodigo) inputCodigo.focus();
                return;
            }

            if (esFormularioNuevoVisible && !nombreNuevo) {
                mostrarMensaje('Escribe el nombre del producto nuevo antes de guardar.', 'warning');
                if (inputNombreNuevo) inputNombreNuevo.focus();
                return;
            }

            if (!navigator.onLine) {
                if (esFormularioNuevoVisible || foto || nombreNuevo) {
                    mostrarMensaje('No se puede crear producto nuevo sin conexión porque la foto debe subirse al servidor.', 'warning');
                    return;
                }
                agregarAColaOffline({
                    local_id: Date.now() + '-' + Math.random().toString(16).slice(2),
                    lote_id: loteId,
                    sku_producto: sku,
                    cantidad: cantidad,
                    fecha: new Date().toISOString()
                });
                mostrarMensaje('Sin conexión. Este escaneo quedó pendiente por sincronizar.', 'warning');
                agregarMovimiento(`SKU ${sku} guardado offline.`, 'warning');
                inputCodigo.value = '';
                inputCantidad.value = '1';
                inputCodigo.focus();
                return;
            }

            const payload = {
                lote_id: loteId,
                sku_producto: sku,
                cantidad: cantidad,
                nombre_nuevo: nombreNuevo,
                foto_producto: foto
            };

            mostrarMensaje('Guardando escaneo...', 'processing');
            try {
                const { response, data } = await enviarFormData(construirFormData(payload));
                await procesarRespuesta(response, data, 'submit');
            } catch (error) {
                mostrarMensaje('No se pudo conectar con el servidor. Revisa internet o intenta de nuevo.', 'error');
                agregarMovimiento('Error de conexión al guardar escaneo.', 'error');
            }
        }



        function obtenerFotoSeleccionada() {
            if (inputFoto && inputFoto.files && inputFoto.files[0]) return inputFoto.files[0];
            if (inputFotoArchivo && inputFotoArchivo.files && inputFotoArchivo.files[0]) return inputFotoArchivo.files[0];
            return null;
        }

        function limpiarOtroInputFoto(origen) {
            if (origen === inputFoto && inputFotoArchivo) inputFotoArchivo.value = '';
            if (origen === inputFotoArchivo && inputFoto) inputFoto.value = '';
        }

        function pintarPreviewFoto(file) {
            if (!previewFoto) return;
            previewFoto.innerHTML = '';
            if (!file) {
                previewFoto.hidden = true;
                return;
            }
            const url = URL.createObjectURL(file);
            const img = document.createElement('img');
            img.src = url;
            img.alt = 'Vista previa de foto del producto';
            previewFoto.appendChild(img);
            previewFoto.hidden = false;
        }

        async function analizarConIA() {
            mostrarMensaje('Preparando análisis con IA...', 'processing');
            if (estadoIA) estadoIA.textContent = 'Preparando análisis con IA...';
            const file = obtenerFotoSeleccionada();
            if (!file) {
                mostrarMensaje('Primero toca Tomar foto o Buscar archivo y selecciona una imagen del producto.', 'warning');
                if (estadoIA) estadoIA.textContent = 'Falta la foto del producto para poder analizar con IA.';
                return;
            }
            if (estadoIA) estadoIA.textContent = 'Analizando foto con IA...';
            if (btnAnalizarIA) btnAnalizarIA.disabled = true;
            const fd = new FormData();
            fd.append('foto_producto', file);
            try {
                const response = await fetch(apiUrlIAProducto(), {
                    method: 'POST',
                    headers: apiHeaders(),
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await leerJsonSeguro(response);
                if (!response.ok || !data.success) {
                    const msg = data.message || data.mensaje || 'No se pudo analizar el producto con IA.';
                    mostrarMensaje(msg, 'error');
                    if (estadoIA) estadoIA.textContent = msg;
                    return;
                }
                const info = data.datos || {};
                if (inputNombreNuevo && info.nombre_sugerido) inputNombreNuevo.value = info.nombre_sugerido;
                if (inputCategoriaIA && info.categoria_sugerida) inputCategoriaIA.value = info.categoria_sugerida;
                if (inputPalabrasIA && info.palabras_clave) inputPalabrasIA.value = info.palabras_clave;
                if (inputDescripcionIA && info.descripcion_corta) inputDescripcionIA.value = info.descripcion_corta;
                const obs = info.observaciones ? ' Observación: ' + info.observaciones : '';
                if (estadoIA) estadoIA.textContent = 'IA completó sugerencias. Revisa la información antes de guardar.' + obs;
                mostrarMensaje('La IA sugirió datos del producto. Revísalos antes de guardar.', 'success');
            } catch (error) {
                const msg = 'Error al conectar con el asistente IA.';
                mostrarMensaje(msg, 'error');
                if (estadoIA) estadoIA.textContent = msg;
            } finally {
                if (btnAnalizarIA) btnAnalizarIA.disabled = false;
            }
        }

        async function abrirCamara() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                mostrarMensaje('Este navegador no permite abrir la cámara. Escribe el SKU manualmente o usa pistola lectora.', 'warning');
                if (inputCodigo) inputCodigo.focus();
                return;
            }

            if (!('BarcodeDetector' in window)) {
                mostrarMensaje('Este navegador no soporta lectura automática de código de barras. Escribe el SKU manualmente o usa una pistola lectora. La foto del producto nuevo sí se toma desde el campo de imagen.', 'warning');
                if (inputCodigo) inputCodigo.focus();
                return;
            }

            try {
                if (cameraModal) cameraModal.hidden = false;
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                cameraVideo.srcObject = stream;
                await cameraVideo.play();

                const detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e', 'qr_code'] });
                cameraHelp.textContent = 'Apunta al código. El sistema lo detectará automáticamente.';

                detectorTimer = setInterval(async function () {
                    try {
                        const codes = await detector.detect(cameraVideo);
                        if (codes && codes.length) {
                            inputCodigo.value = codes[0].rawValue || '';
                            cerrarCamara();
                            mostrarMensaje('Código detectado. Revisa cantidad y guarda el escaneo.', 'success');
                            inputCantidad.focus();
                        }
                    } catch (e) {}
                }, 450);
            } catch (e) {
                cerrarCamara();
                mostrarMensaje('No se pudo abrir la cámara. Revisa permisos del navegador o usa escritura manual.', 'error');
            }
        }

        function cerrarCamara() {
            if (detectorTimer) clearInterval(detectorTimer);
            detectorTimer = null;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            if (cameraVideo) cameraVideo.srcObject = null;
            if (cameraModal) cameraModal.hidden = true;
        }

        if (btnNuevo) btnNuevo.addEventListener('click', function () {
            abrirProductoNuevo('Completa nombre y foto para registrar un producto nuevo.');
        });

        if (btnTomarFoto && inputFoto) btnTomarFoto.addEventListener('click', function () {
            inputFoto.click();
        });
        if (btnBuscarFoto && inputFotoArchivo) btnBuscarFoto.addEventListener('click', function () {
            inputFotoArchivo.click();
        });

        function manejarCambioFoto(origen) {
            return function () {
                limpiarOtroInputFoto(origen);
                const file = origen && origen.files && origen.files[0] ? origen.files[0] : null;
                pintarPreviewFoto(file);
            };
        }

        if (inputFoto) inputFoto.addEventListener('change', manejarCambioFoto(inputFoto));
        if (inputFotoArchivo) inputFotoArchivo.addEventListener('change', manejarCambioFoto(inputFotoArchivo));

        if (btnAnalizarIA) btnAnalizarIA.addEventListener('click', analizarConIA);
        if (btnCamara) btnCamara.addEventListener('click', abrirCamara);
        if (closeCamera) closeCamera.addEventListener('click', cerrarCamara);
        if (cameraModal) cameraModal.addEventListener('click', function (e) {
            if (e.target === cameraModal) cerrarCamara();
        });
        if (inputLote) inputLote.addEventListener('change', validarLoteSeleccionado);
        form.addEventListener('submit', manejarSubmit);
        window.addEventListener('online', function () { actualizarEstadoConexionUI(); sincronizarColaOffline(); });
        window.addEventListener('offline', actualizarEstadoConexionUI);

        actualizarEstadoConexionUI();
        actualizarContadorUI();
        validarLoteSeleccionado();
        if (navigator.onLine) sincronizarColaOffline();
    });
})();

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('mm-app-precios-form');
        if (!form) return;

        const msg = document.getElementById('mm-app-precios-msg');
        const btnEnviar = document.getElementById('mm-app-enviar-aprobacion');
        const btnAprobarCargar = document.getElementById('mm-app-aprobar-cargar');

        function ajaxUrl() {
            return (window.mmApiSettings && mmApiSettings.ajaxUrl) ? mmApiSettings.ajaxUrl : '/wp-admin/admin-ajax.php';
        }

        function showMessage(text, type) {
            if (!msg) return;
            msg.hidden = false;
            msg.textContent = text || '';
            msg.className = 'mm-message ' + (type ? 'mm-message-' + type : '');
        }

        function formatMoney(value) {
            const num = Number(value || 0);
            return '$' + Math.round(num).toLocaleString('es-CO');
        }

        function updateMargins() {
            form.querySelectorAll('tbody tr').forEach(function (row) {
                const costo = Number((row.querySelector('.mm-costo-input') || {}).value || 0);
                const precio = Number((row.querySelector('.mm-precio-input') || {}).value || 0);
                const cell = row.querySelector('.mm-margen-cell');
                if (!cell) return;
                if (precio <= 0) {
                    cell.innerHTML = '<span class="mm-text-danger">Sin precio</span>';
                } else {
                    cell.textContent = formatMoney(precio - costo);
                }
            });
        }

        function buildData(action) {
            const data = new FormData(form);
            data.append('action', action);
            data.append('lote_id', form.dataset.loteId || '0');
            data.append('nonce', form.dataset.nonce || '');
            return data;
        }

        async function send(action) {
            const response = await fetch(ajaxUrl(), {
                method: 'POST',
                body: buildData(action),
                credentials: 'same-origin'
            });
            const data = await response.json().catch(function () { return {}; });
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo completar la acción.');
            }
            return data.data || {};
        }

        form.addEventListener('input', function (event) {
            if (event.target.classList.contains('mm-price-input')) updateMargins();
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            showMessage('Guardando precios...', 'processing');
            try {
                const data = await send('mm_app_guardar_precios');
                showMessage(data.message || 'Precios guardados correctamente.', 'success');
            } catch (error) {
                showMessage(error.message, 'error');
            }
        });

        if (btnEnviar) {
            btnEnviar.addEventListener('click', async function () {
                if (!confirm('¿Enviar este lote a aprobación de jefatura?')) return;
                showMessage('Enviando a aprobación...', 'processing');
                try {
                    await send('mm_app_guardar_precios');
                    const data = await send('mm_app_enviar_aprobacion');
                    showMessage(data.message || 'Lote enviado a aprobación.', 'success');
                    setTimeout(function () { window.location.href = window.location.href.split('&lote_id=')[0]; }, 900);
                } catch (error) {
                    showMessage(error.message, 'error');
                }
            });
        }


        if (btnAprobarCargar) {
            btnAprobarCargar.addEventListener('click', async function () {
                if (!confirm('¿Autorizar el precio final y cargar este lote al sistema? Esta acción enviará el inventario y los precios finales a WooCommerce.')) return;
                showMessage('Aprobando y cargando al sistema...', 'processing');
                try {
                    await send('mm_app_guardar_precios');
                    const data = await send('mm_app_aprobar_cargar');
                    showMessage(data.message || 'Precio autorizado y lote cargado.', 'success');
                    setTimeout(function () {
                        window.location.href = data.redirect || '?mm_logistica_app=etiquetas';
                    }, 1000);
                } catch (error) {
                    showMessage(error.message, 'error');
                }
            });
        }

        updateMargins();
    });
})();


    document.querySelectorAll('.mm-btn-marcar-impreso').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const row = btn.closest('.mm-product-print-row');
            if (!row || !window.mmApiSettings) return;
            const loteId = row.getAttribute('data-lote-id');
            const itemId = row.getAttribute('data-item-id');
            const nonce = row.getAttribute('data-nonce');

            btn.disabled = true;
            const oldText = btn.textContent;
            btn.textContent = 'Guardando...';

            const fd = new FormData();
            fd.append('action', 'mm_app_marcar_etiqueta_impresa');
            fd.append('lote_id', loteId);
            fd.append('item_id', itemId);
            fd.append('nonce', nonce);

            try {
                const response = await fetch(mmApiSettings.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error((data.data && data.data.message) || data.message || 'No se pudo marcar la etiqueta.');
                }
                const status = row.querySelector('.mm-print-status');
                if (status) {
                    status.textContent = (data.data.status === 'reimpreso' ? 'Reimpreso' : 'Impreso') + (data.data.print_count > 1 ? ' · ' + data.data.print_count + ' veces' : '');
                    status.className = 'mm-print-status mm-print-status-' + data.data.status;
                }
                btn.textContent = data.data.status === 'reimpreso' ? 'Registrar reimpresión' : 'Registrar reimpresión';
                const link = row.querySelector('a');
                if (link) link.textContent = 'Reimprimir';
            } catch (error) {
                alert(error.message);
                btn.textContent = oldText;
            } finally {
                btn.disabled = false;
            }
        });
    });


    document.querySelectorAll('.mm-btn-print-custom').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = btn.closest('.mm-product-print-row');
            const input = row ? row.querySelector('.mm-custom-print-qty') : null;
            const base = btn.getAttribute('data-print-base');
            const qty = input ? parseInt(input.value || '0', 10) : 0;

            if (!base) return;
            if (!qty || qty < 1) {
                alert('Escribe una cantidad válida de etiquetas.');
                return;
            }

            const sep = base.indexOf('?') >= 0 ? '&' : '?';
            window.open(base + sep + 'print_qty=' + encodeURIComponent(qty), '_blank', 'noopener');
        });
    });


document.querySelectorAll('.mm-btn-notification-read').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const fd = new FormData();
        fd.append('action', 'mm_app_marcar_notificacion_vista');
        fd.append('nonce', btn.getAttribute('data-nonce') || '');
        if (btn.getAttribute('data-mark-all')) {
            fd.append('mark_all', '1');
        } else {
            fd.append('notification_id', btn.getAttribute('data-notification-id') || '');
        }

        btn.disabled = true;
        const oldText = btn.textContent;
        btn.textContent = 'Guardando...';

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo marcar la notificación.');
            }
            window.location.reload();
        } catch (error) {
            alert(error.message);
            btn.textContent = oldText;
            btn.disabled = false;
        }
    });
});


document.querySelectorAll('.mm-product-review-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const msg = form.querySelector('.mm-product-review-msg');
        const btn = form.querySelector('button[type="submit"]');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_guardar_revision_producto_nuevo');

        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-product-review-msg is-processing';
            msg.textContent = 'Guardando revisión...';
        }
        if (btn) btn.disabled = true;

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo guardar.');
            }
            if (msg) {
                msg.className = 'mm-product-review-msg is-success';
                msg.textContent = data.data.message || 'Guardado correctamente.';
            }
            const badge = form.querySelector('.mm-badge-soft');
            if (badge) {
                badge.textContent = 'Revisado';
                badge.classList.remove('mm-badge-warn');
            }
        } catch (error) {
            if (msg) {
                msg.className = 'mm-product-review-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
        } finally {
            if (btn) btn.disabled = false;
        }
    });
});


document.querySelectorAll('.mm-btn-retry-sync').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        if (!confirm('¿Reintentar la carga de este lote a WooCommerce?')) return;
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const card = btn.closest('.mm-sync-card');
        const msg = card ? card.querySelector('.mm-sync-message') : null;
        const fd = new FormData();
        fd.append('action', 'mm_app_reintentar_sincronizacion_lote');
        fd.append('lote_id', btn.getAttribute('data-lote-id') || '');
        fd.append('nonce', btn.getAttribute('data-nonce') || '');

        btn.disabled = true;
        const oldText = btn.textContent;
        btn.textContent = 'Reintentando...';
        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-sync-message is-processing';
            msg.textContent = 'Solicitando reintento de sincronización...';
        }

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo reintentar.');
            }
            if (msg) {
                msg.className = 'mm-sync-message is-success';
                msg.textContent = data.data.message || 'Reintento solicitado.';
            }
            setTimeout(function(){ window.location.reload(); }, 1200);
        } catch (error) {
            if (msg) {
                msg.className = 'mm-sync-message is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
            btn.textContent = oldText;
            btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-factura-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const msg = form.querySelector('.mm-factura-msg');
        const btn = form.querySelector('button[type="submit"]');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_guardar_factura_lote');

        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-factura-msg is-processing';
            msg.textContent = 'Guardando factura...';
        }
        if (btn) btn.disabled = true;

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo guardar la factura.');
            }
            if (msg) {
                msg.className = 'mm-factura-msg is-success';
                msg.textContent = data.data.message || 'Factura guardada.';
            }
            setTimeout(function(){ window.location.reload(); }, 900);
        } catch (error) {
            if (msg) {
                msg.className = 'mm-factura-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
            if (btn) btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-btn-delete-factura').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        if (!confirm('¿Eliminar esta factura del lote?')) return;
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const fd = new FormData();
        fd.append('action', 'mm_app_eliminar_factura_lote');
        fd.append('lote_id', btn.getAttribute('data-lote-id') || '');
        fd.append('factura_id', btn.getAttribute('data-factura-id') || '');
        fd.append('nonce', btn.getAttribute('data-nonce') || '');

        btn.disabled = true;
        try {
            const response = await fetch(mmApiSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo eliminar.');
            }
            window.location.reload();
        } catch (error) {
            alert(error.message);
            btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-factura-ia-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const msg = form.querySelector('.mm-factura-ia-msg');
        const resultForm = document.querySelector('.mm-factura-ia-result');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_analizar_factura_ia');

        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-factura-ia-msg is-processing';
            msg.textContent = 'Analizando factura...';
        }

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (parseError) {
                throw new Error('WordPress devolvió un error interno al analizar el PDF. Prueba pegando el texto de la factura o revisa el log del servidor.');
            }
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo analizar la factura.');

            const info = data.data.data || {};
            if (resultForm) {
                resultForm.hidden = false;
                const setVal = (name, value) => {
                    const field = resultForm.querySelector('[name="' + name + '"]');
                    if (field) field.value = value || '';
                };
                setVal('numero_factura', info.numero_factura);
                setVal('proveedor', info.proveedor);
                setVal('fecha_factura', info.fecha_factura);
                setVal('total_factura', info.total_factura);
                setVal('observaciones_ia', (info.observaciones_ia || '') + (info.confianza ? '\nConfianza: ' + info.confianza : '') + (info.nit_proveedor ? '\nNIT: ' + info.nit_proveedor : '') + (info.pedido_numero ? '\nPedido: ' + info.pedido_numero : '') + (info.iva_detectado ? '\nIVA detectado: $' + info.iva_detectado : ''));

                const list = resultForm.querySelector('.mm-factura-products-list');
                if (list) {
                    let hiddenProducts = resultForm.querySelector('input[name="productos_detectados_json"]');
                if (!hiddenProducts) {
                    hiddenProducts = document.createElement('input');
                    hiddenProducts.type = 'hidden';
                    hiddenProducts.name = 'productos_detectados_json';
                    resultForm.appendChild(hiddenProducts);
                }
                hiddenProducts.value = JSON.stringify(info.productos_detectados || []);

                const products = info.productos_detectados || [];
                    if (!products.length) {
                        list.textContent = 'Sin productos detectados. Bodega podrá escanearlos manualmente.';
                    } else {
                        list.innerHTML = products.map(function (p) {
                            return '<div class="mm-factura-product-line"><strong>' + (p.codigo ? p.codigo + ' - ' : '') + (p.nombre || '') + '</strong><span>Cantidad esperada: ' + (p.cantidad || 0) + (p.valor_total ? ' · Total: $' + p.valor_total : '') + '</span></div>';
                        }).join('');
                    }
                }
            }

            if (msg) {
                msg.className = 'mm-factura-ia-msg is-success';
                msg.textContent = data.data.message || 'Factura analizada.';
            }
        } catch (error) {
            if (msg) {
                msg.className = 'mm-factura-ia-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
        }
    });
});

document.querySelectorAll('.mm-btn-crear-lote-factura').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const form = btn.closest('.mm-factura-ia-result');
        const msg = form ? form.querySelector('.mm-factura-crear-msg') : null;
        if (!form || !window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const fd = new FormData(form);
        const uploadForm = document.querySelector('.mm-factura-ia-form');
        const uploadInput = uploadForm ? uploadForm.querySelector('input[name="archivo_ia"]') : null;
        if (uploadInput && uploadInput.files && uploadInput.files.length) {
            fd.append('archivo_ia', uploadInput.files[0]);
        }
        fd.append('action', 'mm_app_crear_lote_desde_factura');

        btn.disabled = true;
        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-factura-crear-msg is-processing';
            msg.textContent = 'Creando lote desde factura...';
        }

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo crear el lote.');

            if (msg) {
                msg.className = 'mm-factura-crear-msg is-success';
                msg.textContent = data.data.message || 'Lote creado.';
            }
            if (data.data.redirect) setTimeout(function(){ window.location.href = data.data.redirect; }, 900);
        } catch (error) {
            if (msg) {
                msg.className = 'mm-factura-crear-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
            btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-btn-copiar-datos-factura').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const result = btn.closest('.mm-factura-ia-result');
        const manual = document.querySelector('.mm-factura-form');
        if (!result || !manual) return;

        ['numero_factura','proveedor','fecha_factura','total_factura'].forEach(function (name) {
            const from = result.querySelector('[name="' + name + '"]');
            const to = manual.querySelector('[name="' + name + '"]');
            if (from && to) to.value = from.value;
        });

        const obsFrom = result.querySelector('[name="observaciones_ia"]');
        const obsTo = manual.querySelector('[name="observaciones"]');
        if (obsFrom && obsTo) obsTo.value = obsFrom.value;

        alert('Datos copiados al formulario manual.');
    });
});

document.querySelectorAll('.mm-factura-ai-config-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const msg = form.querySelector('.mm-factura-ai-config-msg');
        const btn = form.querySelector('button[type="submit"]');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_guardar_factura_ai_webhook');

        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-factura-ai-config-msg is-processing';
            msg.textContent = 'Guardando configuración...';
        }
        if (btn) btn.disabled = true;

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const raw = await response.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (parseError) {
                throw new Error('WordPress devolvió una respuesta no válida al guardar el webhook.');
            }
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo guardar.');
            }

            if (msg) {
                msg.className = 'mm-factura-ai-config-msg is-success';
                msg.textContent = data.data.message || 'Webhook guardado.';
            }
        } catch (error) {
            if (msg) {
                msg.className = 'mm-factura-ai-config-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
        } finally {
            if (btn) btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-multi-prices-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const msg = form.querySelector('.mm-multi-prices-msg');
        const btn = form.querySelector('button[type="submit"]');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_guardar_precios_multiples');

        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-multi-prices-msg is-processing';
            msg.textContent = 'Guardando precios múltiples...';
        }
        if (btn) btn.disabled = true;

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida. Revisa que el plugin esté actualizado y que la IA de Pedidos esté activa.'); }
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo guardar.');
            }

            let message = data.data.message || 'Precios guardados.';
            if (data.data.warnings && data.data.warnings.length) {
                message += ' Advertencias: ' + data.data.warnings.join(' | ');
            }

            if (msg) {
                msg.className = data.data.warnings && data.data.warnings.length ? 'mm-multi-prices-msg is-warning' : 'mm-multi-prices-msg is-success';
                msg.textContent = message;
            }
        } catch (error) {
            if (msg) {
                msg.className = 'mm-multi-prices-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
        } finally {
            if (btn) btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-bodega-ia-analyze-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const msg = form.querySelector('.mm-bodega-ia-msg');
        const resultForm = document.querySelector('.mm-bodega-ia-result-form');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_analizar_factura_ia');

        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-bodega-ia-msg is-processing';
            msg.textContent = 'Analizando factura...';
        }

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida al analizar la factura.'); }
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo analizar.');

            const info = data.data.data || {};
            if (resultForm) {
                const setVal = (name, value) => {
                    const field = resultForm.querySelector('[name="' + name + '"]');
                    if (field) field.value = value || '';
                };

                setVal('numero_factura', info.numero_factura);
                setVal('proveedor', info.proveedor);
                setVal('fecha_factura', info.fecha_factura);
                setVal('total_factura', info.total_factura);
                setVal('observaciones_ia', (info.observaciones_ia || '') + (info.confianza ? '\nConfianza: ' + info.confianza : '') + (info.nit_proveedor ? '\nNIT: ' + info.nit_proveedor : '') + (info.pedido_numero ? '\nPedido: ' + info.pedido_numero : ''));

                const hidden = resultForm.querySelector('[name="productos_detectados_json"]');
                if (hidden) hidden.value = JSON.stringify(info.productos_detectados || []);

                const list = resultForm.querySelector('.mm-bodega-ia-products-list');
                if (list) {
                    const products = info.productos_detectados || [];
                    if (!products.length) {
                        list.textContent = 'No se detectaron productos. Puedes guardar datos de factura o escanear manualmente.';
                    } else {
                        list.innerHTML = products.map(function (p) {
                            return '<div class="mm-factura-product-line"><strong>' + (p.codigo ? p.codigo + ' - ' : '') + (p.nombre || '') + '</strong><span>Cantidad esperada: ' + (p.cantidad || 0) + '</span></div>';
                        }).join('');
                    }
                }
            }

            if (msg) {
                msg.className = 'mm-bodega-ia-msg is-success';
                msg.textContent = data.data.message || 'Factura analizada.';
            }
        } catch (error) {
            if (msg) {
                msg.className = 'mm-bodega-ia-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
        }
    });
});

document.querySelectorAll('.mm-btn-bodega-guardar-factura-ia').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;
        const form = btn.closest('.mm-bodega-ia-result-form');
        const msg = form ? form.querySelector('.mm-bodega-ia-save-msg') : null;
        if (!form) return;

        const fd = new FormData(form);
        fd.append('action', 'mm_app_bodega_guardar_factura_ia');

        btn.disabled = true;
        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-bodega-ia-save-msg is-processing';
            msg.textContent = 'Guardando productos esperados...';
        }

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida. Revisa que el plugin esté actualizado y que la IA de Pedidos esté activa.'); }
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo guardar.');

            if (msg) {
                msg.className = 'mm-bodega-ia-save-msg is-success';
                msg.textContent = data.data.message || 'Guardado.';
            }
            setTimeout(function(){ window.location.reload(); }, 900);
        } catch (error) {
            if (msg) {
                msg.className = 'mm-bodega-ia-save-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
            btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-pedido-form, .mm-pedido-compra-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;
        const msg = form.querySelector('.mm-pedido-msg, .mm-pedido-form-msg');
        const btn = form.querySelector('button[type="submit"]');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_guardar_pedido_compra');
        if (msg) { msg.hidden = false; msg.className = 'mm-pedido-msg is-processing'; msg.textContent = 'Guardando pedido...'; }
        if (btn) btn.disabled = true;
        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida. Revisa que el plugin esté actualizado y que la IA de Pedidos esté activa.'); }
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo guardar.');
            if (msg) { msg.className = 'mm-pedido-form-msg is-success'; msg.textContent = data.data.message || 'Pedido guardado.'; }
            setTimeout(function(){
                if (data.data && data.data.lote_id) {
                    window.location.href = (data.data.bodega_url || ('/?mm_logistica_app=bodega&lote_id=' + data.data.lote_id));
                } else {
                    window.location.href = (data.data && data.data.redirect) ? data.data.redirect : '/?mm_logistica_app=pedidos';
                }
            }, 900);
        } catch (error) {
            if (msg) { msg.className = 'mm-pedido-msg is-error'; msg.textContent = error.message; } else { alert(error.message); }
        } finally { if (btn) btn.disabled = false; }
    });
});

document.querySelectorAll('.mm-pedido-factura-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;
        const msg = form.querySelector('.mm-pedido-card-msg');
        const btn = form.querySelector('button[type="submit"]');
        const fd = new FormData(form);
        fd.append('action', 'mm_app_actualizar_factura_pedido');
        if (msg) { msg.hidden = false; msg.className = 'mm-pedido-card-msg is-processing'; msg.textContent = 'Actualizando factura...'; }
        if (btn) btn.disabled = true;
        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida. Revisa que el plugin esté actualizado y que la IA de Pedidos esté activa.'); }
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo actualizar.');
            if (msg) { msg.className = 'mm-pedido-card-msg is-success'; msg.textContent = data.data.message || 'Actualizado.'; }
            setTimeout(function(){ window.location.reload(); }, 800);
        } catch (error) {
            if (msg) { msg.className = 'mm-pedido-card-msg is-error'; msg.textContent = error.message; } else { alert(error.message); }
        } finally { if (btn) btn.disabled = false; }
    });
});

document.querySelectorAll('.mm-btn-pedido-lote').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;
        const card = btn.closest('.mm-pedido-card');
        const msg = card ? card.querySelector('.mm-pedido-card-msg') : null;
        const fd = new FormData();
        fd.append('action', 'mm_app_crear_lote_desde_pedido');
        fd.append('pedido_id', btn.dataset.pedidoId || '');
        fd.append('nonce', btn.dataset.nonce || '');
        btn.disabled = true;
        if (msg) { msg.hidden = false; msg.className = 'mm-pedido-card-msg is-processing'; msg.textContent = 'Creando lote...'; }
        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida. Revisa que el plugin esté actualizado y que la IA de Pedidos esté activa.'); }
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo crear lote.');
            if (msg) { msg.className = 'mm-pedido-card-msg is-success'; msg.textContent = data.data.message || 'Lote creado.'; }
            if (data.data.redirect) setTimeout(function(){ window.location.href = data.data.redirect; }, 800); else setTimeout(function(){ window.location.reload(); }, 800);
        } catch (error) {
            if (msg) { msg.className = 'mm-pedido-card-msg is-error'; msg.textContent = error.message; } else { alert(error.message); }
            btn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-btn-pedido-cerrar').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        if (!confirm('¿Cerrar este pedido?')) return;
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;
        const fd = new FormData();
        fd.append('action', 'mm_app_cerrar_pedido_compra');
        fd.append('pedido_id', btn.dataset.pedidoId || '');
        fd.append('nonce', btn.dataset.nonce || '');
        btn.disabled = true;
        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || data.message || 'No se pudo cerrar.');
            window.location.reload();
        } catch (error) { alert(error.message); btn.disabled = false; }
    });
});

document.querySelectorAll('.mm-pedido-productos-panel').forEach(function (panel) {
    const rows = panel.querySelector('.mm-pedido-productos-rows');
    const addBtn = panel.querySelector('.mm-add-pedido-product-row');

    function getRowHtml() {
        return `
            <tr>
                <td><input class="mm-input" type="text" name="pedido_producto_codigo[]" placeholder="770..."></td>
                <td><input class="mm-input" type="text" name="pedido_producto_nombre[]" placeholder="Nombre del producto"></td>
                <td><input class="mm-input" type="number" name="pedido_producto_cantidad[]" min="0" step="1" placeholder="0"></td>
                <td><input class="mm-input" type="number" name="pedido_producto_costo[]" min="0" step="1" placeholder="Privado"></td>
                <td><input class="mm-input" type="number" name="pedido_producto_precio[]" min="0" step="1" placeholder="Privado"></td>
                <td><input class="mm-input" type="text" name="pedido_producto_observacion[]" placeholder="Color, referencia, nota"></td>
                <td class="mm-pedido-image-cell"><input class="mm-input mm-pedido-product-image-input" type="file" name="pedido_producto_imagen[]" accept="image/jpeg,image/png,image/webp" capture="environment"><div class="mm-pedido-image-preview">Sin imagen</div></td>
                <td><button type="button" class="mm-mini-secondary mm-remove-pedido-product-row">Eliminar</button></td>
            </tr>
        `;
    }

    if (addBtn && rows) {
        addBtn.addEventListener('click', function () {
            rows.insertAdjacentHTML('beforeend', getRowHtml());
        });
    }

    panel.addEventListener('click', function (event) {
        const removeBtn = event.target.closest('.mm-remove-pedido-product-row');
        if (!removeBtn || !rows) return;

        const allRows = rows.querySelectorAll('tr');
        if (allRows.length <= 1) {
            const row = removeBtn.closest('tr');
            if (row) {
                row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
            }
            return;
        }

        const row = removeBtn.closest('tr');
        if (row) row.remove();
    });
});

document.querySelectorAll('.mm-pedido-compra-form').forEach(function (form) {
    const iaBtn = form.querySelector('.mm-pedido-ia-analizar');
    if (!iaBtn) return;

    function moneyToNumber(value) {
        if (value === undefined || value === null) return '';
        const cleaned = String(value).replace(/[^0-9.]/g, '');
        return cleaned || '';
    }

    function setField(name, value) {
        const field = form.querySelector('[name="' + name + '"]');
        if (field && value !== undefined && value !== null && String(value).trim() !== '') {
            field.value = value;
        }
    }

    function ensureProductRows(count) {
        const rows = form.querySelector('.mm-pedido-productos-rows');
        const addBtn = form.querySelector('.mm-add-pedido-product-row');
        if (!rows || !addBtn) return [];
        while (rows.querySelectorAll('tr').length < count) {
            addBtn.click();
        }
        return Array.from(rows.querySelectorAll('tr'));
    }

    function fillProducts(products) {
        const rows = ensureProductRows(products.length || 1);
        products.forEach(function (product, index) {
            const row = rows[index];
            if (!row) return;

            const setRow = (selector, value) => {
                const input = row.querySelector(selector);
                if (input && value !== undefined && value !== null) input.value = value;
            };

            setRow('[name="pedido_producto_codigo[]"]', product.codigo || product.sku || '');
            setRow('[name="pedido_producto_nombre[]"]', product.nombre || product.descripcion || '');
            setRow('[name="pedido_producto_cantidad[]"]', product.cantidad || '');
            setRow('[name="pedido_producto_costo[]"]', moneyToNumber(product.costo || product.costo_unitario || ''));
            setRow('[name="pedido_producto_precio[]"]', moneyToNumber(product.precio || product.precio_sugerido || ''));
            setRow('[name="pedido_producto_observacion[]"]', product.observacion || product.iva || '');
        });
    }

    iaBtn.addEventListener('click', async function () {
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const msg = form.querySelector('.mm-pedido-ia-msg');
        const archivo = form.querySelector('[name="pedido_ia_archivo"]');
        const texto = form.querySelector('[name="pedido_ia_texto"]');

        const fd = new FormData();
        fd.append('action', 'mm_app_analizar_pedido_ia');
        fd.append('nonce', iaBtn.dataset.nonce || '');
        if (archivo && archivo.files && archivo.files[0]) {
            fd.append('archivo_ia', archivo.files[0]);
        }
        if (texto && texto.value) {
            fd.append('texto_factura', texto.value);
        }

        if ((!archivo || !archivo.files || !archivo.files[0]) && (!texto || !texto.value.trim())) {
            alert('Sube un archivo o pega texto para que la IA pueda analizar.');
            return;
        }

        iaBtn.disabled = true;
        if (msg) {
            msg.hidden = false;
            msg.className = 'mm-pedido-ia-msg is-processing';
            msg.textContent = 'Analizando soporte...';
        }

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida. Revisa que el plugin esté actualizado y que la IA de Pedidos esté activa.'); }
            if (!response.ok || !data.success) {
                throw new Error((data.data && data.data.message) || data.message || 'No se pudo analizar con IA.');
            }

            const info = data.data.data || {};
            const products = info.productos_detectados || [];

            setField('proveedor', info.proveedor || '');
            setField('factura_numero', info.numero_factura || '');
            setField('factura_total', moneyToNumber(info.total_factura || ''));
            if (info.fecha_factura) setField('fecha_pedido', info.fecha_factura);

            if (info.numero_factura || info.total_factura) {
                setField('estado_factura', 'recibida');
            }

            if (products.length) {
                fillProducts(products);
            }

            if (msg) {
                msg.className = products.length ? 'mm-pedido-ia-msg is-success' : 'mm-pedido-ia-msg is-warning';
                let diagnostic = '';
                if (!products.length) {
                    try {
                        diagnostic = '\n\nDiagnóstico n8n/plugin:\n' + JSON.stringify(info, null, 2).slice(0, 1800);
                    } catch (e) {}
                }
                let usageNote = '';
                if (info._ai_usage && info._ai_usage.last) {
                    usageNote = ' Tokens: ' + (info._ai_usage.last.total_tokens || 0) + ' · Costo aprox: $' + (info._ai_usage.last.cost_usd || 0) + ' USD.';
                }
                msg.textContent = products.length
                    ? 'IA completó datos y productos. Revisa antes de guardar.' + usageNote
                    : 'IA analizó el soporte, pero no detectó productos. Revisa el diagnóstico debajo.' + diagnostic;
            }
        } catch (error) {
            if (msg) {
                msg.className = 'mm-pedido-ia-msg is-error';
                msg.textContent = error.message;
            } else {
                alert(error.message);
            }
        } finally {
            iaBtn.disabled = false;
        }
    });
});

document.querySelectorAll('.mm-ia-config-form').forEach(function (form) {
    const msg = form.querySelector('.mm-ia-config-msg');
    const testBtn = form.querySelector('.mm-ia-test-btn');

    function showMsg(type, text) {
        if (!msg) return;
        msg.hidden = false;
        msg.className = 'mm-ia-config-msg ' + type;
        msg.textContent = text;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

        const fd = new FormData(form);
        fd.append('action', 'mm_app_guardar_config_ia');

        showMsg('is-processing', 'Guardando configuración...');

        try {
            const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida.'); }
            if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || 'No se pudo guardar.');
            showMsg('is-success', data.data.message || 'Configuración guardada.');
        } catch (error) {
            showMsg('is-error', error.message);
        }
    });

    if (testBtn) {
        testBtn.addEventListener('click', async function () {
            if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

            const fd = new FormData(form);
            fd.append('action', 'mm_app_probar_config_ia');
            fd.set('nonce', testBtn.dataset.nonce || '');

            testBtn.disabled = true;
            showMsg('is-processing', 'Probando conexión con n8n...');

            try {
                const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
                const raw = await response.text();
                let data = null;
                try { data = JSON.parse(raw); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida.'); }
                if (!response.ok || !data.success) throw new Error((data.data && data.data.message) || 'No se pudo conectar.');
                showMsg('is-success', data.data.message || 'Conexión exitosa.');
            } catch (error) {
                showMsg('is-error', error.message);
            } finally {
                testBtn.disabled = false;
            }
        });
    }
});

document.querySelectorAll('.mm-ia-config-form').forEach(function (form) {
    const msg = form.querySelector('.mm-ia-config-msg');
    const productTestBtn = form.querySelector('.mm-ia-product-test-btn');

    function showDiagnosticMsg(type, text, raw) {
        if (!msg) return;
        msg.hidden = false;
        msg.className = 'mm-ia-config-msg ' + type;
        let extra = '';
        if (raw) {
            try {
                extra = '\n\nRespuesta n8n:\n' + JSON.stringify(raw, null, 2).slice(0, 1500);
            } catch (e) {}
        }
        msg.textContent = text + extra;
    }

    if (productTestBtn) {
        productTestBtn.addEventListener('click', async function () {
            if (!window.mmApiSettings || !mmApiSettings.ajaxUrl) return;

            const fd = new FormData(form);
            fd.append('action', 'mm_app_probar_ia_producto');
            fd.set('nonce', productTestBtn.dataset.nonce || '');

            productTestBtn.disabled = true;
            showDiagnosticMsg('is-processing', 'Probando si n8n devuelve productos...');

            try {
                const response = await fetch(mmApiSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
                const rawText = await response.text();
                let data = null;
                try { data = JSON.parse(rawText); } catch (e) { throw new Error('WordPress devolvió una respuesta no válida.'); }

                if (!response.ok || !data.success) {
                    throw {
                        message: (data.data && data.data.message) || 'n8n no devolvió productos.',
                        raw: data.data && data.data.raw ? data.data.raw : data
                    };
                }

                showDiagnosticMsg('is-success', data.data.message || 'Diagnóstico correcto.', data.data.raw || data.data);
            } catch (error) {
                showDiagnosticMsg('is-error', error.message || 'No se pudo probar análisis con producto.', error.raw || null);
            } finally {
                productTestBtn.disabled = false;
            }
        });
    }
});

document.querySelectorAll('.mm-exhibicion-form').forEach(function(form){
  const msg=form.querySelector('.mm-exhibicion-msg');
  form.addEventListener('submit',async function(e){e.preventDefault();const fd=new FormData(form);fd.append('action','mm_app_guardar_exhibicion');if(msg){msg.hidden=false;msg.className='mm-exhibicion-msg is-processing';msg.textContent='Guardando...';}try{const r=await fetch(mmApiSettings.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});const d=await r.json();if(!r.ok||!d.success)throw new Error((d.data&&d.data.message)||'No se pudo guardar');if(msg){msg.className='mm-exhibicion-msg is-success';msg.textContent=d.data.message||'Guardado';}setTimeout(()=>location.reload(),500);}catch(err){if(msg){msg.className='mm-exhibicion-msg is-error';msg.textContent=err.message;}}});
});
document.querySelectorAll('.mm-exhibicion-move-form').forEach(function(form){form.addEventListener('submit',async function(e){e.preventDefault();const fd=new FormData(form);fd.append('action','mm_app_mover_exhibicion');try{const r=await fetch(mmApiSettings.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});const d=await r.json();if(!r.ok||!d.success)throw new Error((d.data&&d.data.message)||'No se pudo mover');location.reload();}catch(err){alert(err.message);}});});

document.querySelectorAll('.mm-bodega-receipt-form').forEach(function(form){
 const msg=form.querySelector('.mm-bodega-receipt-msg');
 function diffs(){form.querySelectorAll('.mm-received-input').forEach(function(inp){const esp=parseInt(inp.dataset.esperado||'0',10);const rec=parseInt(inp.value||'0',10);const d=inp.closest('tr')?.querySelector('.mm-receipt-diff');if(d)d.textContent=rec-esp;});}
 form.addEventListener('input',function(e){if(e.target.classList.contains('mm-received-input'))diffs();});
 async function send(action){const fd=new FormData(form);fd.append('action',action);if(msg){msg.hidden=false;msg.className='mm-bodega-receipt-msg is-processing';msg.textContent=action==='mm_app_finalizar_bodega_lote'?'Finalizando bodega y alimentando inventario...':'Guardando cantidades...';}const r=await fetch(mmApiSettings.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});const data=await r.json();if(!r.ok||!data.success)throw new Error((data.data&&data.data.message)||'Error en bodega.');if(msg){msg.className='mm-bodega-receipt-msg is-success';msg.textContent=data.data.message||'Listo.';}if(data.data&&data.data.redirect){setTimeout(()=>{window.location.href=data.data.redirect;},800);}}
 form.addEventListener('submit',async function(e){e.preventDefault();try{await send('mm_app_guardar_bodega_receipt');}catch(err){if(msg){msg.className='mm-bodega-receipt-msg is-error';msg.textContent=err.message;}else alert(err.message);}});
 const btn=form.querySelector('.mm-finalizar-bodega-lote'); if(btn){btn.addEventListener('click',async function(){if(!confirm('¿Finalizar Bodega y alimentar inventario físico?'))return;try{await send('mm_app_finalizar_bodega_lote');}catch(err){if(msg){msg.className='mm-bodega-receipt-msg is-error';msg.textContent=err.message;}else alert(err.message);}});}
 diffs();
});


/* bodega-autoload-lote-v461 */
(function(){
    const selects = Array.from(document.querySelectorAll('select'));
    selects.forEach(function(select){
        const name = (select.getAttribute('name') || '').toLowerCase();
        const id = (select.getAttribute('id') || '').toLowerCase();
        const text = (select.closest('.mm-platform-section,.mm-scan-card,form,main')?.innerText || '').toLowerCase();

        const looksLikeLote = name.includes('lote') || id.includes('lote') || text.includes('selecciona el lote') || text.includes('lote activo');
        if (!looksLikeLote) return;

        select.addEventListener('change', function(){
            const loteId = this.value;
            if (!loteId) return;
            const url = new URL(window.location.href);
            url.searchParams.set('mm_logistica_app', 'bodega');
            url.searchParams.set('lote_id', loteId);
            url.searchParams.set('lote', loteId);
            window.location.href = url.toString();
        });
    });
})();


/* bodega-safe-autoload-v465 */
(function(){
    document.querySelectorAll('select').forEach(function(select){
        const name = (select.getAttribute('name') || '').toLowerCase();
        const id = (select.getAttribute('id') || '').toLowerCase();
        const containerText = (select.closest('.mm-platform-section,.mm-scan-card,form,main')?.innerText || '').toLowerCase();
        const isLote = name.includes('lote') || id.includes('lote') || containerText.includes('selecciona el lote') || containerText.includes('lote activo');

        if (!isLote || select.dataset.mmBodegaAutoload === '1') return;
        select.dataset.mmBodegaAutoload = '1';

        select.addEventListener('change', function(){
            const loteId = this.value;
            if (!loteId) return;
            const url = new URL(window.location.href);
            url.searchParams.set('mm_logistica_app', 'bodega');
            url.searchParams.set('lote_id', loteId);
            url.searchParams.set('lote', loteId);
            window.location.href = url.toString();
        });
    });
})();

document.querySelectorAll('.mm-resolver-producto-sin-imagen').forEach(function(btn){
    btn.addEventListener('click', async function(){
        const fd = new FormData();
        fd.append('action','mm_app_resolver_producto_sin_imagen');
        fd.append('index', this.dataset.index || '');
        const res = await fetch(mmApiSettings.ajaxUrl, {method:'POST', credentials:'same-origin', body:fd});
        const data = await res.json();
        if (!res.ok || !data.success) { alert((data.data && data.data.message) || 'No se pudo marcar.'); return; }
        location.reload();
    });
});


/* bodega-validacion-final-v469 */
(function(){
    function validateBodegaForm(form){
        const rows = Array.from(form.querySelectorAll('tbody tr'));
        let complete = 0;
        let pending = 0;
        let errors = [];

        rows.forEach(function(row, idx){
            const codigoInput = row.querySelector('input[name*="[codigo]"]');
            const nombreInput = row.querySelector('input[name*="[nombre]"]');
            const recibidoInput = row.querySelector('input[name*="[recibido]"]');
            const codigoText = (codigoInput ? codigoInput.value : (row.children[0]?.innerText || '')).trim();
            const nombreText = (nombreInput ? nombreInput.value : row.innerText).trim();
            const recibido = recibidoInput ? parseInt(recibidoInput.value || '0', 10) : 0;
            const rowErrors = [];

            if (!codigoText) rowErrors.push('SKU vacío');
            if (!nombreText) rowErrors.push('producto vacío');
            if (!recibido || recibido <= 0) rowErrors.push('cantidad recibida sin confirmar');

            const status = row.querySelector('[data-status-row], .mm-bodega-row-status');
            if (rowErrors.length) {
                pending++;
                row.classList.add('mm-bodega-row-error');
                row.classList.remove('mm-bodega-row-ok');
                if (status) {
                    status.textContent = '🔴 Error';
                    status.classList.add('is-error');
                    status.classList.remove('is-ok');
                }
                errors.push('Fila ' + (idx + 1) + ': ' + rowErrors.join(', '));
            } else {
                complete++;
                row.classList.add('mm-bodega-row-ok');
                row.classList.remove('mm-bodega-row-error');
                if (status) {
                    status.textContent = '🟢 Completo';
                    status.classList.add('is-ok');
                    status.classList.remove('is-error');
                }
            }
        });

        const completeEl = form.querySelector('[data-complete-count]');
        const pendingEl = form.querySelector('[data-pending-count]');
        if (completeEl) completeEl.textContent = complete;
        if (pendingEl) pendingEl.textContent = pending;

        return {ok: pending === 0, complete, pending, errors};
    }

    document.querySelectorAll('.mm-bodega-receipt-form').forEach(function(form){
        form.addEventListener('input', function(){
            validateBodegaForm(form);
        });

        const finish = form.querySelector('.mm-finalizar-bodega-lote');
        if (finish && !finish.dataset.validationPatched) {
            finish.dataset.validationPatched = '1';
            finish.addEventListener('click', function(e){
                const validation = validateBodegaForm(form);
                if (!validation.ok) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    alert(
                        'No se puede finalizar Bodega todavía.\n\n' +
                        'Productos completos: ' + validation.complete + '\n' +
                        'Productos pendientes: ' + validation.pending + '\n\n' +
                        validation.errors.slice(0, 10).join('\n')
                    );
                    return false;
                }
            }, true);
        }

        validateBodegaForm(form);
    });
})();


function mmUpdatePedidoImagePreview(input) {
    const cell = input.closest('.mm-pedido-image-cell');
    if (!cell) return;
    const preview = cell.querySelector('.mm-pedido-image-preview');
    if (!preview) return;

    if (!input.files || !input.files[0]) {
        preview.textContent = 'Sin imagen';
        preview.classList.remove('has-image');
        preview.style.backgroundImage = '';
        return;
    }

    const file = input.files[0];
    const url = URL.createObjectURL(file);
    preview.textContent = '';
    preview.classList.add('has-image');
    preview.style.backgroundImage = 'url("' + url + '")';
}

document.addEventListener('change', function(e){
    const input = e.target.closest('.mm-pedido-product-image-input');
    if (!input) return;
    mmUpdatePedidoImagePreview(input);
});
