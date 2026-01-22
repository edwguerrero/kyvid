/**
 * connections.js
 * Manages the "Servicios Externos" module (IA, SMTP, Telegram, etc.)
 */

const ConnectionsManager = {
    API_URL: 'api/connections.php',
    _items: [], // Store current items locally to avoid quote escaping issues in HTML

    init: function () {
        console.log("ConnectionsManager initialized.");
        if ($('#connectionsList').length) {
            this.loadList();
        }
    },

    loadList: function () {
        $.getJSON(this.API_URL, { action: 'list' }, (response) => {
            if (response.success) {
                this._items = response.data;
                this.renderList();
            } else {
                Swal.fire('Error', response.error || 'No se pudieron cargar los servicios.', 'error');
            }
        }).fail(() => {
            $('#connectionsList').html('<tr><td colspan="5" class="text-center text-danger">Error de Servidor</td></tr>');
        });
    },

    renderList: function () {
        const container = $('#connectionsList');
        container.empty();

        if (this._items.length === 0) {
            container.html(`
                <tr>
                    <td colspan="5" class="text-center text-muted py-5">
                        <i class="bi bi-outlet fs-1 d-block mb-3"></i>
                        <h5>Sin servicios configurados</h5>
                        <p>Agrega credenciales de SMTP, IA, Telegram, etc.</p>
                        <button class="btn btn-sm btn-outline-warning mt-3" onclick="ConnectionsManager.runMigration()">
                            <i class="bi bi-arrow-repeat me-1"></i> Intentar Migrar Datos Antiguos
                        </button>
                    </td>
                </tr>
            `);
            return;
        }

        this._items.forEach((conn, index) => {
            const isActive = parseInt(conn.is_active) === 1;
            const statusBadge = isActive ?
                '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Activo</span>' :
                '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill">Inactivo</span>';

            // Icon selection
            let icon = 'bi-hdd-network';
            if (conn.type === 'SMTP') icon = 'bi-envelope-at';
            else if (conn.type === 'AI') icon = 'bi-cpu';
            else if (conn.type === 'TELEGRAM') icon = 'bi-telegram';
            else if (conn.type === 'N8N') icon = 'bi-lightning-charge';
            else if (conn.type === 'DRIVE') icon = 'bi-google-drive';

            const providerBadge = conn.provider ? `<span class="badge bg-info-subtle text-info border border-info-subtle ms-1">${conn.provider}</span>` : '';

            const row = `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light text-primary rounded me-3 d-flex align-items-center justify-content-center" style="width:40px; height:40px;">
                                <i class="bi ${icon} fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark">${conn.name}</div>
                                <small class="text-muted">${conn.type} ${providerBadge}</small>
                            </div>
                        </div>
                    </td>
                    <td>
                        ${this.renderConfigPreview(conn.config)}
                    </td>
                    <td>${statusBadge}</td>
                    <td><small class="text-muted">${conn.created_at ? conn.created_at.split(' ')[0] : '-'}</small></td>
                    <td class="text-end">
                        <div class="btn-group shadow-sm">
                            <button class="btn btn-sm btn-white border" onclick="ConnectionsManager.testConnection(${conn.id}, '${conn.type}')" title="Probar">
                                <i class="bi bi-activity text-warning"></i>
                            </button>
                            <button class="btn btn-sm btn-white border" onclick="ConnectionsManager.openEditByIndex(${index})" title="Editar">
                                <i class="bi bi-pencil text-primary"></i>
                            </button>
                            <button class="btn btn-sm btn-white border" onclick="ConnectionsManager.deleteConnection(${conn.id})" title="Eliminar">
                                <i class="bi bi-trash text-danger"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            container.append(row);
        });
    },

    renderConfigPreview: function (config) {
        if (!config) return '<span class="text-muted small">Sin config.</span>';
        const keys = ['host', 'model', 'username'];
        let parts = [];
        keys.forEach(k => {
            if (config[k]) parts.push(`<span class="badge bg-light text-dark font-monospace fw-normal" style="font-size:0.7rem">${k}: ${config[k]}</span>`);
        });
        return parts.length ? parts.join(' ') : '<span class="text-muted small">-</span>';
    },

    openModal: function () {
        $('#connId').val('');
        $('#connForm')[0].reset();
        $('#connType').change();
        $('#connectionModalLabel').text('Nuevo Servicio');
        $('#connectionModal').modal('show');
    },

    openEditByIndex: function (index) {
        const data = this._items[index];
        if (!data) return;

        $('#connId').val(data.id);
        $('#connName').val(data.name);
        $('#connType').val(data.type).change();
        $('#connProvider').val(data.provider || ''); // Ensure this is set before the timeout
        $('#connIsActive').prop('checked', data.is_active == 1);

        // Populate dynamic fields
        setTimeout(() => {
            if (data.config) {
                $.each(data.config, (k, v) => {
                    $(`#conf_${k}`).val(v);
                });
            }
            if (data.type === 'AI' && data.provider) {
                $('#connProviderSelect').val(data.provider);
            }
            $('.cred-input').val('*****');
        }, 100);

        $('#connectionModalLabel').text('Editar Servicio');
        $('#connectionModal').modal('show');
    },

    save: function () {
        const id = $('#connId').val();
        const type = $('#connType').val();

        let payload = {
            id: id || null,
            name: $('#connName').val(),
            type: type,
            provider: $('#connProvider').val(),
            is_active: $('#connIsActive').is(':checked') ? 1 : 0,
            config: {},
            creds: {}
        };

        if (payload.type === 'AI' && !payload.provider) {
            payload.provider = 'openai';
        }

        if (!payload.name) { Swal.fire('Error', 'El nombre es obligatorio', 'warning'); return; }

        $('#dynamicFieldsContainer input, #dynamicFieldsContainer select, #dynamicFieldsContainer textarea').each(function () {
            const fieldId = $(this).attr('id');
            if (!fieldId) return;
            const val = $(this).val();

            if (fieldId.startsWith('conf_')) {
                payload.config[fieldId.replace('conf_', '')] = val;
            } else if (fieldId.startsWith('cred_')) {
                payload.creds[fieldId.replace('cred_', '')] = val;
            }
        });

        $.ajax({
            url: this.API_URL + '?action=save',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: (resp) => {
                if (resp.success) {
                    $('#connectionModal').modal('hide');
                    Swal.fire({ icon: 'success', title: 'Guardado', timer: 1500, showConfirmButton: false });
                    this.loadList();
                } else {
                    Swal.fire('Error', resp.error || 'Fallo al guardar', 'error');
                }
            }
        });
    },

    deleteConnection: function (id) {
        Swal.fire({
            title: '¿Eliminar Servicio?',
            text: "Esta acción no se puede deshacer.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Sí, eliminar'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: this.API_URL + '?action=delete',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ id: id }),
                    success: (resp) => {
                        if (resp.success) {
                            this.loadList();
                            Swal.fire('Eliminado', '', 'success');
                        } else {
                            Swal.fire('Error', resp.error, 'error');
                        }
                    }
                });
            }
        });
    },

    testConnection: function (id, type) {
        if (!id) {
            Swal.fire('Info', 'Por seguridad, guarde la conexión antes de probarla.', 'info');
            return;
        }

        const runTest = (recipient = null) => {
            Swal.fire({
                title: 'Probando Conexión...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: this.API_URL + '?action=test',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: id, type: type, recipient: recipient }),
                success: (resp) => {
                    Swal.close();
                    if (resp.success) {
                        Swal.fire('¡Éxito!', resp.message, 'success');
                    } else {
                        Swal.fire('Fallo', resp.error, 'error');
                    }
                },
                error: (xhr) => {
                    Swal.close();
                    console.error("DEBUG: Server Response Text:", xhr.responseText);
                    let msg = (xhr.status === 200) ? 'Fallo al procesar respuesta (JSON inválido). Ver consola para detalles.' : 'Fallo de conexión (HTTP ' + xhr.status + ')';
                    Swal.fire('Error de backend', msg, 'error');
                }
            });
        };

        if (type === 'SMTP') {
            Swal.fire({
                title: 'Probar Correo',
                text: 'Ingrese un email para enviar el correo de prueba:',
                input: 'email',
                inputPlaceholder: 'ejemplo@correo.com',
                showCancelButton: true,
                confirmButtonText: 'Enviar Prueba'
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    runTest(result.value);
                }
            });
        } else {
            runTest();
        }
    },

    runMigration: function () {
        Swal.fire({
            title: 'Migración',
            text: '¿Importar credenciales antiguas?',
            icon: 'question',
            showCancelButton: true
        }).then((res) => {
            if (res.isConfirmed) {
                Swal.showLoading();
                $.get('api/migrate_to_connections.php', (output) => {
                    Swal.close();
                    this.loadList();
                    Swal.fire('Migración completada', '', 'success');
                });
            }
        });
    },

    renderFormFields: function () {
        const type = $('#connType').val();
        const container = $('#dynamicFieldsContainer');
        container.empty();

        if (type === 'SMTP') {
            $('#connProvider').val('gmail');
            container.append(`
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Host SMTP</label>
                        <input type="text" class="form-control form-control-sm" id="conf_host" value="smtp.gmail.com">
                    </div>
                    <div class="col-md-4">
                         <label class="form-label">Puerto</label>
                         <input type="number" class="form-control form-control-sm" id="conf_port" value="465">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email / Usuario</label>
                        <input type="email" class="form-control form-control-sm" id="conf_username">
                    </div>
                    <div class="col-md-6">
                         <label class="form-label">Contraseña / Token</label>
                         <input type="password" class="form-control form-control-sm cred-input" id="cred_password">
                    </div>
                </div>
             `);
        } else if (type === 'AI') {
            if (!$('#connProvider').val()) $('#connProvider').val('openai');
            container.append(`
                <div class="mb-3">
                    <label class="form-label small fw-bold text-primary">Proveedor de IA</label>
                    <select class="form-select form-select-sm" id="connProviderSelect" onchange="ConnectionsManager.updateProvider(this.value)">
                         <option value="openai">OpenAI (Gpt-4, etc)</option>
                         <option value="gemini">Google Gemini (Flash, Pro)</option>
                         <option value="anthropic">Anthropic (Claude)</option>
                         <option value="groq">Groq (Ultra-fast)</option>
                         <option value="deepseek">DeepSeek</option>
                    </select>
                </div>
                <script>
                    $('#connProviderSelect').val($('#connProvider').val() || 'openai');
                </script>
                <div class="row g-3 mb-3">
                     <div class="col-12">
                         <label class="form-label small">API Key</label>
                         <input type="password" class="form-control form-control-sm cred-input" id="cred_api_key">
                     </div>
                     <div class="col-md-6">
                         <label class="form-label small">Modelo ID</label>
                         <input type="text" class="form-control form-control-sm" id="conf_model" placeholder="gpt-4...">
                     </div>
                     <div class="col-md-6">
                           <label class="form-label small">Base URL (Opcional)</label>
                           <input type="text" class="form-control form-control-sm" id="conf_base_url" placeholder="https://api...">
                     </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">System Prompt (Generación SQL)</label>
                    <textarea class="form-control form-control-sm" id="conf_system_prompt" rows="3">Eres un experto en SQL. Genera JSON crudo basado en el schema. REGLA DE RENDIMIENTO: Filtros automáticos en pedidos amplios. Incluye "phpscript2" en el JSON.</textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">System Prompt (Estrategia / Análisis)</label>
                    <textarea class="form-control form-control-sm" id="conf_analysis_prompt" rows="3">Eres un analista de datos experto. Tu tarea es analizar los resultados de una consulta SQL y proporcionar información clave, tendencias y recomendaciones. Responde de manera concisa y profesional, destacando los puntos más relevantes para la toma de decisiones.</textarea>
                </div>
              `);
        } else if (type === 'TELEGRAM') {
            $('#connProvider').val('telegram');
            container.append(`
                 <div class="mb-3">
                     <label class="form-label small">Bot Token</label>
                     <input type="text" class="form-control form-control-sm cred-input" id="cred_bot_token">
                 </div>
                 <div class="mb-3">
                     <label class="form-label small">Chat ID Default</label>
                     <input type="text" class="form-control form-control-sm cred-input" id="cred_chat_id">
                 </div>
             `);
        } else if (type === 'N8N') {
            $('#connProvider').val('n8n');
            container.append(`
                  <div class="mb-3">
                      <label class="form-label small">Webhook Auth Header</label>
                      <input type="text" class="form-control form-control-sm cred-input" id="cred_auth_header">
                  </div>
              `);
        }
    },

    updateProvider: function (val) {
        $('#connProvider').val(val);
    }
};

window.ConnectionsManager = ConnectionsManager;
window.openConnectionModal = () => {
    $('#connId').val('');
    $('#connForm')[0].reset();
    $('#connType').val('SMTP').change();
    $('#connectionModalLabel').text('Nuevo Servicio');
    $('#connectionModal').modal('show');
};
