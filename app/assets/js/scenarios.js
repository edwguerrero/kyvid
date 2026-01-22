// assets/js/scenarios.js

let grid = null;
let currentScenarioId = null;
let scenariosCache = {};
let widgetDataCache = {};
let currentScenarioFilters = {};

$(document).ready(function () {
    try {
        initGrid();
        loadScenarios();
    } catch (e) {
        console.error("Error al inicializar escenarios:", e);
    }

    $('#scenarioSelect').on('change', function () {
        const id = $(this).val();
        if (id) {
            loadScenarioDetails(id);
            $('#btnEditScenario, #btnDeleteScenario').prop('disabled', false);
        } else {
            $('#scenarioTitle').text('Canvas de Escenario');
            $('#scenarioActions').addClass('d-none');
            $('#btnEditScenario, #btnDeleteScenario').prop('disabled', true);
            if (grid) grid.removeAll();
            widgetDataCache = {};
            $('#scenarioFilterForm').empty();
            $('#scenarioFilterActions').addClass('d-none');
            $('#noFiltersMsg').show();
        }
    });
});

function initGrid() {
    if (grid) return;
    grid = GridStack.init({
        float: true,
        cellHeight: 'auto',
        margin: 5,
        removable: '.trash-can',
        acceptWidgets: true,
        column: 12,
        disableOneColumnMode: false,
        oneColumnSize: 768
    }, '#scenarioGrid');
}



function loadScenarios() {
    $.get('api/scenarios.php?action=list', function (resp) {
        if (resp.success) {
            const select = $('#scenarioSelect');
            select.empty().append('<option value="">Seleccione un escenario...</option>');
            scenariosCache = {};
            resp.data.forEach(s => {
                scenariosCache[s.id] = s;
                select.append(`<option value="${s.id}">${s.name}</option>`);
            });
        }
    });
}

function loadScenarioDetails(id) {
    currentScenarioId = id;
    widgetDataCache = {};
    currentScenarioFilters = {}; // Reset global filters

    $.get('api/scenarios.php?action=get&id=' + id, function (resp) {
        if (resp.success) {
            const scenario = resp.data;
            $('#scenarioTitle').text(scenario.name);
            $('#scenarioActions').removeClass('d-none');

            grid.removeAll();

            // Collect all parameters from all widgets to build master filter
            let allParams = [];

            if (scenario.widgets && scenario.widgets.length > 0) {
                scenario.widgets.forEach(w => {
                    // Collect params
                    try {
                        if (w.parameters_json) {
                            const params = JSON.parse(w.parameters_json);
                            params.forEach(p => allParams.push(p));
                        }
                    } catch (e) { }

                    // Render Widget
                    let layout = { x: 0, y: 0, w: 4, h: 3 };
                    try {
                        if (w.grid_layout) {
                            const parsed = JSON.parse(w.grid_layout);
                            if (parsed.x !== undefined) layout = parsed;
                        }
                    } catch (e) { }

                    const widgetHtml = `
                        <div class="grid-stack-item" gs-id="${w.id}" gs-x="${layout.x}" gs-y="${layout.y}" gs-w="${layout.w}" gs-h="${layout.h}">
                            <div class="grid-stack-item-content">
                                <div class="widget-header">
                                    <span class="fw-bold"><i class="bi bi-file-earmark-text me-1"></i>${w.title_override || w.report_name}</span>
                                    <div class="btn-group">
                                        <button class="btn btn-xs btn-outline-secondary border-0" onclick="removeWidget(${w.id})"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                                <div class="widget-body" id="widget-body-${w.id}">
                                    <div class="text-center p-3 text-muted"><span class="spinner-border spinner-border-sm"></span></div>
                                </div>
                            </div>
                        </div>
                    `;
                    grid.addWidget(widgetHtml);
                    // Load data initially without extra filters (or use saved defaults if implemented later)
                    loadWidgetData(w);
                });
            }

            renderScenarioFilters(allParams);
        }
    });
}

function renderScenarioFilters(allParams) {
    const container = $('#scenarioFilterForm');
    container.empty();
    const uniqueParams = {};

    // Deduplicate params by LABEL
    allParams.forEach(p => {
        if (!uniqueParams[p.label]) {
            uniqueParams[p.label] = p;
        }
    });

    const paramsList = Object.values(uniqueParams);

    if (paramsList.length === 0) {
        $('#noFiltersMsg').show();
        $('#scenarioFilterActions').addClass('d-none');
        $('#scenarioFiltersAccordion').addClass('disabled');
        return;
    }

    $('#noFiltersMsg').hide();
    $('#scenarioFilterActions').removeClass('d-none');
    $('#scenarioFiltersAccordion').removeClass('disabled');

    paramsList.forEach(p => {
        // Use LABEL as the key identifier for the MASTER filter
        const masterKey = p.label;
        const id = 'sf_' + masterKey.replace(/\s+/g, '_');
        let inputHtml = '';

        if (p.type === 'date') {
            inputHtml = `<input type="date" class="form-control form-control-sm" id="${id}" name="${masterKey}">`;
        } else if (p.type === 'date_range') {
            const now = new Date();
            const today = now.toISOString().split('T')[0];
            const past = new Date();
            past.setMonth(now.getMonth() - 1);
            const monthAgo = past.toISOString().split('T')[0];

            inputHtml = `
                <div class="input-group input-group-sm">
                    <input type="date" class="form-control" name="${masterKey}[]" value="${monthAgo}" title="Desde">
                    <span class="input-group-text px-1">-</span>
                    <input type="date" class="form-control" name="${masterKey}[]" value="${today}" title="Hasta">
                </div>
             `;
        } else if (p.type === 'select') {
            let opts = '<option value="">Todos</option>';
            if (p.options) {
                p.options.forEach(o => opts += `<option value="${o}">${o}</option>`);
                inputHtml = `<select class="form-select form-select-sm" id="${id}" name="${masterKey}">${opts}</select>`;
            } else {
                inputHtml = `<input type="text" class="form-control form-control-sm" id="${id}" name="${masterKey}" placeholder="Texto...">`;
            }
        } else {
            inputHtml = `<input type="text" class="form-control form-control-sm" id="${id}" name="${masterKey}">`;
        }

        const colClass = p.type === 'date_range' ? 'col-md-4 col-sm-6' : 'col-md-3 col-sm-6';

        const html = `
            <div class="${colClass}">
                <label class="form-label small mb-1">${p.label}</label>
                ${inputHtml}
            </div>
        `;
        container.append(html);
    });
}

function applyScenarioFilters() {
    currentScenarioFilters = {};

    // Collect values Keyed by LABEL (the Master Key)
    const formData = new FormData($('#scenarioFilterForm')[0]);
    for (let [key, value] of formData.entries()) {
        if (key.endsWith('[]')) {
            let cleanKey = key.slice(0, -2);
            if (!currentScenarioFilters[cleanKey]) currentScenarioFilters[cleanKey] = [];
            currentScenarioFilters[cleanKey].push(value);
        } else if (value.trim() !== "") {
            currentScenarioFilters[key] = value;
        }
    }

    if (!currentScenarioId) return;

    // Reload widgets
    $.get('api/scenarios.php?action=get&id=' + currentScenarioId, function (resp) {
        if (resp.success) {
            const scenario = resp.data;
            scenario.widgets.forEach(w => {
                // Pass GLOBAL filters (Key=Label) to loadWidgetData
                loadWidgetData(w, currentScenarioFilters);
            });
        }
    });
}

function resetScenarioFilters() {
    $('#scenarioFilterForm')[0].reset();
    currentScenarioFilters = {};
    applyScenarioFilters();
}

// Updated signature to accept filters
function loadWidgetData(widget, masterFilters = {}) {

    // MAP Master Filters (Label) -> Report Filters (Field)
    let specificFilters = {};

    try {
        if (widget.parameters_json && Object.keys(masterFilters).length > 0) {
            const params = JSON.parse(widget.parameters_json);
            params.forEach(p => {
                // If we have a value for this parameter's LABEL in our master filters
                if (masterFilters[p.label]) {
                    // Assign it to the specific FIELD required by the API
                    specificFilters[p.field] = masterFilters[p.label];
                }
            });
        }
    } catch (e) { console.error("Error mapping filters", e); }

    const payload = {
        report_id: widget.report_id,
        filters: specificFilters, // Use MAPPED filters
        draw: 1,
        start: 0,
        length: 10,
        search: { value: '' }
    };

    const container = $(`#widget-body-${widget.id}`);
    container.html('<div class="text-center p-3 text-muted"><span class="spinner-border spinner-border-sm"></span> Cargando...</div>');

    $.ajax({
        url: 'api/index.php?action=execute',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        success: function (resp) {
            container.empty();
            if (resp.success) {
                widgetDataCache[widget.id] = {
                    name: widget.title_override || widget.report_name,
                    columns: resp.columns,
                    data: resp.data
                };

                if (widget.display_type === 'chart' && resp.data.length > 0) {
                    container.html(`<canvas id="canvas-widget-${widget.id}"></canvas>`);
                    renderWidgetChart(widget.id, resp.data, resp.columns);
                } else {
                    renderWidgetTable(container, resp.data, resp.columns);
                }
            } else {
                container.html(`<div class="text-danger small">Error: ${resp.error}</div>`);
            }
        },
        error: function () {
            container.html('<div class="text-danger small">Error de conexión</div>');
        }
    });
}

function renderWidgetTable(container, data, columns) {
    if (!data || data.length === 0) {
        container.html('<div class="text-center text-muted small py-4">No hay datos</div>');
        return;
    }
    const maxCols = 6;
    let html = '<table class="table table-sm table-hover table-striped" style="font-size: 0.75rem;"><thead><tr>';
    columns.slice(0, maxCols).forEach(c => html += `<th>${c}</th>`);
    html += '</tr></thead><tbody>';
    data.slice(0, 8).forEach(row => {
        html += '<tr>';
        const vals = Object.values(row);
        vals.slice(0, maxCols).forEach(v => html += `<td>${v}</td>`);
        html += '</tr>';
    });
    html += '</tbody></table>';
    container.html(html);
}

function renderWidgetChart(widgetId, data, columns) {
    const el = document.getElementById(`canvas-widget-${widgetId}`);
    if (!el) return;
    const ctx = el.getContext('2d');
    const labels = data.map(r => Object.values(r)[0]);
    const values = data.map(r => parseFloat(Object.values(r)[columns.length - 1]) || 0);

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                borderColor: (typeof APP_THEME !== 'undefined') ? APP_THEME.primary : '#198754',
                borderWidth: 2,
                fill: true,
                backgroundColor: (typeof APP_THEME !== 'undefined') ? APP_THEME.primaryLight : 'rgba(25, 135, 84, 0.1)',
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { display: false },
                y: { display: true, ticks: { font: { size: 9 }, color: (typeof APP_THEME !== 'undefined') ? APP_THEME.secondary : '#666' } }
            }
        }
    });
}

function openNewScenario() {
    Swal.fire({
        title: 'Nuevo Escenario',
        input: 'text',
        inputLabel: 'Nombre del Escenario',
        inputPlaceholder: 'Ej: Comercial Global, Financiero...',
        showCancelButton: true,
        confirmButtonText: 'Crear',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            $.ajax({
                url: 'api/scenarios.php?action=save',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ name: result.value }),
                success: function (resp) {
                    if (resp.success) {
                        loadScenarios();
                        Swal.fire('Creado', 'Escenario listo.', 'success');
                    }
                }
            });
        }
    });
}

function editCurrentScenario() {
    if (!currentScenarioId) return;
    const scenario = scenariosCache[currentScenarioId];
    Swal.fire({
        title: 'Editar Escenario',
        input: 'text',
        inputValue: scenario.name,
        showCancelButton: true,
        confirmButtonText: 'Guardar'
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            $.ajax({
                url: 'api/scenarios.php?action=save',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: currentScenarioId, name: result.value }),
                success: function (resp) {
                    if (resp.success) {
                        loadScenarios();
                        $('#scenarioTitle').text(result.value);
                    }
                }
            });
        }
    });
}

function deleteCurrentScenario() {
    if (!currentScenarioId) return;
    Swal.fire({
        title: '¿Eliminar escenario?',
        text: 'Se perderán la configuración de widgets.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74a3b',
        confirmButtonText: 'Eliminar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/scenarios.php?action=delete_scenario',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: currentScenarioId }),
                success: function (resp) {
                    if (resp.success) {
                        currentScenarioId = null;
                        loadScenarios();
                        $('#scenarioSelect').val('').trigger('change');
                        Swal.fire('Eliminado', '', 'success');
                    }
                }
            });
        }
    });
}

function openAddWidget() {
    if (!currentScenarioId) return;
    let options = '';
    if (typeof reportsCache !== 'undefined') {
        Object.keys(reportsCache).forEach(id => {
            options += `<option value="${id}">${reportsCache[id].name}</option>`;
        });
    }

    Swal.fire({
        title: 'Añadir Reporte',
        html: `
            <select id="swalReportSelect" class="form-select mb-3">${options}</select>
            <select id="swalDisplayType" class="form-select">
                <option value="table">Tabla (Resumen)</option>
                <option value="chart">Mini Gráfico (Tendencia)</option>
            </select>
        `,
        showCancelButton: true,
        confirmButtonText: 'Añadir',
        preConfirm: () => {
            return {
                report_id: document.getElementById('swalReportSelect').value,
                display_type: document.getElementById('swalDisplayType').value
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const data = {
                scenario_id: currentScenarioId,
                report_id: result.value.report_id,
                display_type: result.value.display_type,
                grid_layout: JSON.stringify({ x: 0, y: 0, w: 4, h: 3 })
            };
            $.ajax({
                url: 'api/scenarios.php?action=save_widget',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function (resp) {
                    if (resp.success) loadScenarioDetails(currentScenarioId);
                }
            });
        }
    });
}

function saveLayout() {
    if (!currentScenarioId) return;
    const widgets = [];
    $('.grid-stack-item').each(function () {
        const node = $(this).data('gs-id') ? { x: $(this).attr('gs-x'), y: $(this).attr('gs-y'), w: $(this).attr('gs-w'), h: $(this).attr('gs-h') } : this.gridstackNode;
        widgets.push({
            id: $(this).attr('gs-id'),
            layout: { x: parseInt($(this).attr('gs-x')), y: parseInt($(this).attr('gs-y')), w: parseInt($(this).attr('gs-w')), h: parseInt($(this).attr('gs-h')) }
        });
    });

    $.ajax({
        url: 'api/scenarios.php?action=update_layouts',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ widgets: widgets }),
        success: function (resp) {
            if (resp.success) Swal.fire({ icon: 'success', title: 'Diseño Guardado', timer: 1000, showConfirmButton: false });
        }
    });
}

function removeWidget(id) {
    Swal.fire({ title: '¿Quitar reporte?', icon: 'question', showCancelButton: true, confirmButtonText: 'Quitar' }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/scenarios.php?action=delete_widget',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: id }),
                success: function (resp) {
                    if (resp.success) {
                        grid.removeWidget($(`.grid-stack-item[gs-id="${id}"]`)[0]);
                        delete widgetDataCache[id];
                    }
                }
            });
        }
    });
}

function generateScenarioAnalysis() {
    if (!currentScenarioId) return;

    let dataContext = "DATOS DEL ESCENARIO (FILTRADOS):\n\n";
    let hasData = false;

    Object.keys(widgetDataCache).forEach(wId => {
        const w = widgetDataCache[wId];
        dataContext += `REPORTE: ${w.name}\n`;
        dataContext += `COLUMNAS: ${w.columns.join(', ')}\n`;
        dataContext += "DATOS (Muestra):\n";
        w.data.slice(0, 10).forEach(row => {
            dataContext += JSON.stringify(row) + "\n";
        });
        dataContext += "\n----------------\n";
        hasData = true;
    });

    if (!hasData) {
        Swal.fire('Atención', 'Espera a que carguen los datos de los widgets antes de analizar.', 'warning');
        return;
    }

    Swal.fire({
        title: 'Analizando Datos...',
        html: 'La IA está interpretando los reportes visibles...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    const promptText = `
        ANALISTA DE NEGOCIOS: Tu tarea es analizar los siguientes DATOS REALES del dashboard.
        
        ${dataContext}
    `;

    $.ajax({
        url: 'api/ai_service.php?action=generate',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            prompt: promptText,
            mode: 'analysis',
            provider: localStorage.getItem('ai_last_provider') || 'openai'
        }),
        success: function (resp) {
            Swal.close();
            if (resp.success) {
                let content = resp.data.description || 'No se generó descripción.';

                // Formateador visual sin librerías externas
                let html = content
                    .replace(/---/g, '<hr>')
                    .replace(/\*\*(.*?)\*\*/g, '<strong class="text-primary">$1</strong>') // Negritas
                    .replace(/^### (.*$)/gim, '<h5 class="fw-bold mt-4 text-dark"><i class="bi bi-chevron-right text-primary me-2"></i>$1</h5>') // H3
                    .replace(/^## (.*$)/gim, '<h4 class="fw-bold mt-4 text-primary border-bottom pb-2">$1</h4>') // H2
                    .replace(/^\* (.*$)/gim, '<li class="list-group-item border-0 py-1"><i class="bi bi-dot text-primary h4 mb-0 me-2"></i>$1</li>') // List items
                    .replace(/^\d+\. (.*$)/gim, '<li class="list-group-item border-0 py-1"><span class="badge bg-primary-light text-primary me-2">$0</span> $1</li>') // Numbered list
                    .replace(/\n/g, '<br>');

                // Envolver listas en list-groups
                if (html.includes('<li')) {
                    html = html.replace(/(<li.*?<\/li>)+/s, '<ul class="list-group list-group-flush bg-transparent">$0</ul>');
                }

                $('#aiNarrativeContent').html(`<div class="mt-2 text-dark" style="line-height: 1.6; font-size: 0.95rem;">${html}</div>`);
                $('#aiScenarioAnalysis').removeClass('d-none');
            } else {
                Swal.fire('Error IA', resp.error, 'error');
            }
        },
    });
}

/**
 * Genera un link público para el escenario con los filtros actuales
 */
function shareScenario() {
    if (!currentScenarioId) return;

    Swal.fire({
        title: 'Generando link de compartido...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: 'api/scenarios.php?action=share_save',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            scenario_id: currentScenarioId,
            filters: currentScenarioFilters
        }),
        success: function (resp) {
            if (resp.success) {
                const baseUrl = window.location.origin + window.location.pathname;
                const shareUrl = `${baseUrl}?stoken=${resp.token}`;

                Swal.fire({
                    title: '¡Escenario Publicado!',
                    html: `
                        <p>Cualquier persona con este link podrá ver el canvas con los filtros actuales:</p>
                        <div class="input-group mb-3">
                            <input type="text" id="shareUrlInput" class="form-control" value="${shareUrl}" readonly>
                            <button class="btn btn-outline-primary" onclick="copyShareUrl()">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    `,
                    icon: 'success',
                    confirmButtonText: 'Cerrar'
                });
            } else {
                Swal.fire('Error', 'No se pudo generar el link: ' + resp.error, 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'Error de comunicación con el servidor', 'error');
        }
    });
}

/**
 * Carga un escenario compartido mediante un token
 */
function loadSharedScenario(token) {
    Swal.fire({
        title: 'Cargando escenario compartido...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.getJSON(`api/scenarios.php?action=share_get&token=${token}`, function (resp) {
        if (resp.success) {
            const shared = resp.data;
            currentScenarioId = shared.id;

            // Render Scenario basic info
            $('#scenarioTitle').text(shared.name);
            $('#scenarioActions').removeClass('d-none');

            // Hide admin buttons and sharing in shared view
            $('#scenarioActions button').addClass('d-none');
            // Only keep "Analizar Estrategia" if you want, but maybe better keep it clean
            // Show analysis button if it was there? Actually index.php has it.
            // Let's hide everything for now.

            if (grid) grid.removeAll();

            try {
                currentScenarioFilters = JSON.parse(shared.shared_filters || '{}');
            } catch (e) {
                currentScenarioFilters = {};
            }

            let allParams = [];
            if (shared.widgets && shared.widgets.length > 0) {
                shared.widgets.forEach(w => {
                    try {
                        if (w.parameters_json) {
                            const params = JSON.parse(w.parameters_json);
                            params.forEach(p => allParams.push(p));
                        }
                    } catch (e) { }

                    let layout = { x: 0, y: 0, w: 4, h: 3 };
                    try {
                        if (w.grid_layout) {
                            const parsed = JSON.parse(w.grid_layout);
                            if (parsed.x !== undefined) layout = parsed;
                        }
                    } catch (e) { }

                    const widgetHtml = `
                        <div class="grid-stack-item" gs-id="${w.id}" gs-x="${layout.x}" gs-y="${layout.y}" gs-w="${layout.w}" gs-h="${layout.h}">
                            <div class="grid-stack-item-content">
                                <div class="widget-header">
                                    <span class="fw-bold"><i class="bi bi-file-earmark-text me-1"></i>${w.title_override || w.report_name}</span>
                                </div>
                                <div class="widget-body" id="widget-body-${w.id}">
                                    <div class="text-center p-3 text-muted"><span class="spinner-border spinner-border-sm"></span></div>
                                </div>
                            </div>
                        </div>
                    `;
                    grid.addWidget(widgetHtml);
                    loadWidgetData(w, currentScenarioFilters);
                });
            }

            renderScenarioFilters(allParams);

            if (Object.keys(currentScenarioFilters).length > 0) {
                for (const [label, val] of Object.entries(currentScenarioFilters)) {
                    const input = $(`[name="${label}"], [name="${label}[]"]`);
                    if (input.length > 0) {
                        if (Array.isArray(val) && input.length > 1) {
                            input.each(function (index) {
                                if (val[index]) $(this).val(val[index]);
                            });
                        } else {
                            input.val(val);
                        }
                    }
                }
            }

            $('title').text(shared.name + ' - Escenario Compartido');
            Swal.close();

        } else {
            Swal.fire('Error', resp.error, 'error');
        }
    });
}
