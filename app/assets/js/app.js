// assets/js/app.js

// CONFIGURACIÓN CENTRAL DE APARIENCIA (Coincide con CSS en index.php)
const APP_THEME = {
    primary: '#198754',         // Verde Corporativo
    primaryLight: 'rgba(25, 135, 84, 0.1)', // Verde suave para fondos
    secondary: '#5a5c69',       // Gris secundario
    accent: '#ffc107',          // Amarillo
    fontFamily: "'Segoe UI', sans-serif"
};

function logout() {
    Swal.fire({
        title: '¿Cerrar Sesión?',
        text: "Tendrás que ingresar tus credenciales nuevamente.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, salir'
    }).then((result) => {
        if (result.isConfirmed) {
            $.get('api/auth.php?action=logout', function () {
                window.location.reload();
            });
        }
    });
}

const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer)
        toast.addEventListener('mouseleave', Swal.resumeTimer)
    }
});

let reportsCache = {};
let dataTable = null;
let reportChart = null;
let currentReportId = null;
let currentFilters = {};
let currentPivotData = null;
let lastPivotConfig = null;

// Robot Synchronization Constants & State
const ROBOT_STATE_KEY = 'robot_active_state';
const ROBOT_HEARTBEAT_KEY = 'robot_master_heartbeat';
let isRobotMaster = false;
let robotInterval = null;

$(document).ready(function () {
    // Silence DataTables native alerts and use SweetAlert
    $.fn.dataTable.ext.errMode = 'none';
    $('#resultsTable').on('error.dt', function (e, settings, techNote, message) {
        console.error('DataTables Error:', message);
        Swal.fire({
            icon: 'warning',
            title: 'Aviso de Datos',
            text: 'Hubo un inconveniente al mostrar los resultados. Verifica la configuración de las columnas.',
            footer: '<small class="text-muted">' + message + '</small>'
        });
    });

    loadReports();
    checkAdminStatus();
    initRobotSync();

    // Accordion handling
    $('#filterBody, #chartBody').on('show.bs.collapse', function () {
        $(this).prev('.filter-toggle').removeClass('collapsed');
    });
    $('#filterBody, #chartBody').on('hide.bs.collapse', function () {
        $(this).prev('.filter-toggle').addClass('collapsed');
    });

    $('#reportSelect').on('change', function () {
        const reportId = $(this).val();
        if (reportId) {
            renderFilters(reportsCache[reportId]);
            $('#resultsCard').addClass('d-none');
            $('#chartCard').addClass('d-none');

            $('#btnEditReport').prop('disabled', false);
            $('#btnDeleteReport').prop('disabled', false);

            if (!$('#filterBody').hasClass('show')) {
                new bootstrap.Collapse(document.getElementById('filterBody'), { show: true });
            }
        } else {
            $('#filterCard').addClass('d-none');
            $('#resultsCard').addClass('d-none');
            $('#chartCard').addClass('d-none');
            $('#btnEditReport').prop('disabled', true);
            $('#btnDeleteReport').prop('disabled', true);
        }
    });

    $('#filterForm').on('submit', function (e) {
        e.preventDefault();
        executeReport();
    });

    $('#btnResetFilters').on('click', function () {
        $('#filterForm')[0].reset();
    });

    // Editor Listeners
    $('#btnNewReport').click(function () {
        $('#editorForm')[0].reset();
        $('#editId').val('');
        $('#editPhp2').val('');
        $('#editorModalLabel').text('Nuevo Reporte');
        $('#btnAiAssist').html('<i class="bi bi-stars"></i> Asistente IA (Generar SQL)');
        new bootstrap.Modal('#editorModal').show();
    });

    $('#btnEditReport').click(function () {
        const id = $('#reportSelect').val();
        const rep = reportsCache[id];

        $('#editId').val(rep.id);
        $('#editCode').val(rep.code);
        $('#editCategory').val(rep.category || 'General');
        $('#editName').val(rep.name);
        $('#editDesc').val(rep.description);
        $('#editSql').val(rep.sql_query);
        $('#editPhp').val(rep.php_script);
        $('#editPhp2').val(rep.phpscript2 || '');
        $('#editCols').val(rep.columns_json);
        $('#editParams').val(rep.parameters_json);
        $('#editCronInterval').val(rep.cron_interval_minutes || 60);
        $('#editIsView').prop('checked', parseInt(rep.is_view) === 1);
        $('#editIsActive').prop('checked', parseInt(rep.is_active) === 1);

        // Grouping & Chart fields
        let groupCfg = { groupCol: '', sumCols: '', chartLabelCol: '', chartValueCol: '' };
        try { if (rep.grouping_config) groupCfg = JSON.parse(rep.grouping_config); } catch (e) { }

        $('#editGroupCol').val(groupCfg.groupCol || '');
        $('#editSumCols').val(groupCfg.sumCols || '');
        $('#editChartLabelCol').val(groupCfg.chartLabelCol || '');
        $('#editChartValueCol').val(groupCfg.chartValueCol || '');

        // ACL Rules
        let aclView = '';
        try {
            let acl = JSON.parse(rep.acl_view || '[]');
            if (Array.isArray(acl)) aclView = acl.join(', ');
        } catch (e) { }
        $('#editAclView').val(aclView);

        // Actions (NEW)
        populateActionsForEditor(rep.post_action_code, rep.post_action_params);

        $('#editorModalLabel').text('Editar Reporte');
        $('#btnAiAssist').html('<i class="bi bi-stars"></i> Editar este informe con IA');
        new bootstrap.Modal('#editorModal').show();
    });

    $('#btnDeleteReport').click(function () {
        Swal.fire({
            title: '¿Eliminar reporte?',
            text: "No podrás revertir esta acción.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                deleteReport($('#reportSelect').val());
            }
        });
    });

    // Restore lost feature: List models on API Key change for Gemini
    $('#aiApiKey').on('change blur', function () {
        if ($('#aiProvider').val() === 'gemini') {
            listGeminiModels();
        }
    });
});

function loadReports() {
    // Add cache buster to ensure we get fresh data after a save
    $.getJSON('api/index.php?action=list&_t=' + Date.now(), function (response) {
        if (response.success) {
            const currentVal = $('#reportSelect').val();
            $('#reportSelect').empty().append('<option value="">Seleccione un reporte...</option>');
            let grouped = {};
            response.data.forEach(report => {
                reportsCache[report.id] = report;
                let cat = report.category || 'General';
                if (!grouped[cat]) grouped[cat] = [];
                grouped[cat].push(report);
            });

            Object.keys(grouped).sort().forEach(cat => {
                let group = $('<optgroup>').attr('label', cat);
                grouped[cat].forEach(r => {
                    const autoIcon = parseInt(r.is_automatic) === 1 ? ' 🤖' : '';
                    const inactiveTag = parseInt(r.is_active) === 0 ? ' (🚫 INACTIVO)' : '';
                    group.append(`<option value="${r.id}">${r.name}${autoIcon}${inactiveTag}</option>`);
                });
                $('#reportSelect').append(group);
            });

            // Restore selection if it still exists
            if (currentVal && response.data.find(r => r.id == currentVal)) {
                $('#reportSelect').val(currentVal);
            }
        } else {
            Swal.fire('Error', 'Error cargando reportes: ' + response.error, 'error');
        }
    });
}

function renderFilters(report) {
    let auditInfo = '';
    if (report.last_execution_at) {
        auditInfo = `<span class="badge bg-secondary ms-2" title="Última ejecución automática"><i class="bi bi-clock-history"></i> ${report.last_execution_at}</span>`;
    }
    $('#reportDescription').html('<i class="bi bi-info-circle me-1"></i>' + (report.description || 'Sin descripción') + auditInfo);
    $('#dynamicFilters').empty();

    let params = [];
    try {
        params = JSON.parse(report.parameters_json);
    } catch (e) {
        console.error("Error parsing params JSON", e);
    }

    if (params && params.length > 0) {
        params.forEach(param => {
            let html = '';
            if (param.type === 'date_range') {
                const now = new Date();
                const today = now.toISOString().split('T')[0];
                const past = new Date();
                past.setMonth(now.getMonth() - 1);
                const monthAgo = past.toISOString().split('T')[0];

                html = `
                    <div class="col-md-6">
                        <label class="form-label">${param.label}</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                            <input type="date" name="${param.field}[]" class="form-control" value="${monthAgo}" required>
                            <span class="input-group-text">hasta</span>
                            <input type="date" name="${param.field}[]" class="form-control" value="${today}" required>
                        </div>
                    </div>
                `;
            } else if (param.type === 'select') {
                let opts = param.options.map(o => `<option value="${o}">${o}</option>`).join('');
                html = `
                    <div class="col-md-4">
                        <label class="form-label">${param.label}</label>
                        <select name="${param.field}" class="form-select">
                            <option value="">(Todos)</option>
                            ${opts}
                        </select>
                    </div>
                `;
            } else {
                html = `
                    <div class="col-md-4">
                        <label class="form-label">${param.label}</label>
                        <div class="input-group">
                            <input type="text" name="${param.field}" class="form-control" placeholder="Escriba para buscar...">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                        </div>
                    </div>
                `;
            }
            $('#dynamicFilters').append(html);
        });
        $('#filterCard').removeClass('d-none');
    } else {
        $('#filterCard').removeClass('d-none');
        $('#dynamicFilters').html('<div class="col-12 text-center text-muted fst-italic">Este reporte no requiere filtros previos. Haga clic en Generar.</div>');
    }
}


function executeReport() {
    // If not in shared mode (or if user selected manually), use the dropdown
    const selectedId = $('#reportSelect').val();
    if (selectedId) {
        currentReportId = selectedId;
    }

    if (!currentReportId) {
        Swal.fire({
            icon: 'warning',
            title: 'Atención',
            text: 'Debe seleccionar un reporte de la lista antes de generar los resultados.',
            confirmButtonText: 'Entendido'
        });
        return;
    }

    const btn = $('button[type="submit"]');
    const originalText = btn.html();

    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Procesando...');

    currentFilters = {};
    const formData = new FormData(document.getElementById('filterForm'));

    for (let [key, value] of formData.entries()) {
        if (key.endsWith('[]')) {
            let cleanKey = key.slice(0, -2);
            if (!currentFilters[cleanKey]) currentFilters[cleanKey] = [];
            currentFilters[cleanKey].push(value);
        } else if (value.trim() !== "") {
            currentFilters[key] = value;
        }
    }

    const initialPayload = {
        report_id: currentReportId,
        filters: currentFilters,
        draw: 1,
        start: 0,
        length: 10,
        search: { value: '' }
    };

    $.ajax({
        url: 'api/index.php?action=execute',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(initialPayload),
        success: function (resp) {
            try {
                if (resp.success) {
                    renderTable(resp);
                    renderChart(resp.data, resp.columns);

                    const report = reportsCache[currentReportId];
                    const hasAction = report && (
                        report.has_phpscript2 == 1 ||
                        (report.phpscript2 && report.phpscript2.trim() !== '') ||
                        (report.post_action_code && report.post_action_code.trim() !== '')
                    );

                    if (hasAction) {
                        $('#btnProcessReport, #btnProcessReportPivot').removeClass('d-none');

                        // Update Action Badge
                        let actionLabel = 'Acción ';
                        if (report.post_action_code) {
                            actionLabel += (report.post_action_code === 'UTIL_EMAIL_REPORT' ? '✉️ Correo' : '⚙️ Auto');
                        } else {
                            actionLabel += '📝 Script';
                        }
                        $('#reportActionBadge').text(actionLabel).removeClass('d-none');

                        // Notify user if a post-action is available
                        if (report.post_action_code) {
                            const actionName = report.post_action_code === 'UTIL_EMAIL_REPORT' ? 'Envío por Correo' : 'Acción Automatizada';
                            Toast.fire({
                                icon: 'info',
                                title: `Este reporte tiene habilitado: ${actionName}. Haga clic en 'Procesar' para ejecutar.`
                            });
                        }
                    } else {
                        $('#btnProcessReport, #btnProcessReportPivot').addClass('d-none');
                        $('#reportActionBadge').addClass('d-none');
                    }

                    new bootstrap.Collapse(document.getElementById('filterBody'), { toggle: false }).hide();
                    $('html, body').animate({
                        scrollTop: $("#resultsCard").offset().top - 20
                    }, 500);
                } else {
                    Swal.fire('Error', 'Error: ' + resp.error, 'error');
                }
            } catch (ex) {
                console.error(ex);
                Swal.fire('Error Interno', 'Error renderizando: ' + ex.message, 'error');
            }
        },
        error: function (err) {
            Swal.fire('Error', 'Error de conexión con el servidor', 'error');
            console.error(err);
        },
        complete: function () {
            btn.prop('disabled', false).html(originalText);
        }
    });
}

function renderTable(response) {
    if (dataTable) {
        dataTable.destroy();
        $('#resultsTable').empty();
        $('#resultsTable').append('<thead><tr id="tableHeaderRow"></tr></thead><tbody></tbody><tfoot id="tableFooter"></tfoot>');
    }

    const report = reportsCache[currentReportId] || {};
    let groupCfg = { groupCol: '', sumCols: '' };
    try { groupCfg = JSON.parse(report.grouping_config || '{}'); } catch (e) { }

    const columns = response.columns;
    const headerRow = $('#tableHeaderRow');
    columns.forEach(col => {
        headerRow.append(`<th>${col}</th>`);
    });

    // Initialize Footer
    const footer = $('#tableFooter').empty();
    const footerRow = $('<tr/>');
    columns.forEach(() => footerRow.append('<th></th>'));
    footer.append(footerRow);

    let dtColumns = [];
    let columnDefs = [];

    if (response.data.length > 0 && response.data[0]) {
        const firstRow = response.data[0];
        const keys = Object.keys(firstRow);

        // Validation: Mismatch Check
        if (keys.length !== columns.length) {
            Swal.fire({
                icon: 'warning',
                title: 'Desajuste de Columnas',
                html: `
                    <p>La consulta SQL devolvió <b>${keys.length}</b> columnas, pero definiste <b>${columns.length}</b> encabezados.</p>
                    <ul class="text-start small">
                        <li><strong>SQL (Datos):</strong> ${keys.join(', ')}</li>
                        <li><strong>Definición (Headers):</strong> ${columns.join(', ')}</li>
                    </ul>
                    <p class="mb-0">Corrige la configuración del reporte.</p>
                `
            });
        }

        const formatQty = (val) => {
            if (val === null || val === undefined || val === '') return '';
            const num = parseFloat(val);
            if (isNaN(num)) return val;
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num);
        };

        dtColumns = keys.map((key, index) => {
            const val = firstRow[key];
            const isNumeric = !isNaN(parseFloat(val)) && isFinite(val) && key.toLowerCase() !== 'id';

            return {
                data: key,
                render: function (data, type, row) {
                    if (type === 'display' && isNumeric) {
                        return formatQty(data);
                    }
                    return data;
                }
            };
        });

        // Auto-detect numeric columns for right alignment
        keys.forEach((key, index) => {
            const val = firstRow[key];
            const isNumeric = !isNaN(parseFloat(val)) && isFinite(val) && key.toLowerCase() !== 'id';
            if (isNumeric) {
                columnDefs.push({ targets: index, className: 'text-end' });
            }
        });
    } else {
        dtColumns = columns.map(() => ({ defaultContent: "" }));
    }

    const sumCols = groupCfg.sumCols ? groupCfg.sumCols.split(',').map(s => s.trim()) : [];

    dataTable = $('#resultsTable').DataTable({
        serverSide: true,
        processing: true,
        data: response.data,
        deferLoading: response.recordsTotal,
        columns: dtColumns,
        columnDefs: columnDefs,
        createdRow: function (row, data, dataIndex) {
            const report = reportsCache[currentReportId];
            const hasAction = report && report.phpscript2 && report.phpscript2.trim() !== '';

            if (hasAction) {
                // Make all cells except the first one (ID) editable only if there's an action
                $(row).find('td').each(function (index) {
                    if (index > 0) {
                        $(this).attr('contenteditable', 'true').addClass('editable-cell');
                    }
                });
            }
        },
        ajax: function (data, callback, settings) {
            const payload = {
                report_id: currentReportId,
                filters: currentFilters,
                draw: data.draw,
                start: data.start,
                length: data.length,
                search: data.search,
                order: data.order,
                columns: data.columns
            };

            $.ajax({
                url: 'api/index.php?action=execute',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                success: function (resp) {
                    if (resp.success) {
                        // Check for post-action warnings
                        if (resp.action_error) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Aviso de Proceso',
                                text: 'El reporte se generó, pero hubo un error en la acción posterior (Email/Integración): ' + resp.action_error
                            });
                        }
                        callback(resp);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error en Sort/Filtro', text: resp.error || 'Ocurrió un error al procesar el reporte.' });
                        // Inform DataTables that the request is done (but empty) to stop the spinner
                        callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                    }
                },
                error: function (xhr) {
                    Swal.fire({ icon: 'error', title: 'Error de Servidor', text: 'No se pudo comunicar con la API para ordenar o filtrar.' });
                    callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                }
            });
        },
        order: groupCfg.groupCol ? [[columns.indexOf(groupCfg.groupCol), 'asc']] : [],
        rowGroup: (groupCfg.groupCol && response.data.length > 0) ? {
            dataSrc: Object.keys(response.data[0])[columns.indexOf(groupCfg.groupCol)],
            startRender: function (rows, group) {
                return $('<tr/>')
                    .append('<td colspan="' + columns.length + '" style="background:#f8f9fc; font-weight:bold;">' + group + ' (' + rows.count() + ' registros)</td>');
            },
            endRender: function (rows, group) {
                if (sumCols.length === 0) return null;
                const formatQty = (val) => {
                    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);
                };
                let tr = $('<tr/>').append('<td style="font-weight:bold;">Subtotal ' + group + '</td>');
                for (let i = 1; i < columns.length; i++) {
                    const firstRow = response.data[0];
                    const colName = Object.keys(firstRow)[i];
                    if (sumCols.indexOf(columns[i]) !== -1) {
                        let innerSum = rows.data().pluck(colName).reduce(function (a, b) {
                            return (parseFloat(a) || 0) + (parseFloat(b) || 0);
                        }, 0);
                        tr.append('<td class="text-end" style="font-weight:bold;">' + formatQty(innerSum) + '</td>');
                    } else {
                        tr.append('<td></td>');
                    }
                }
                return tr;
            }
        } : null,
        responsive: true,
        dom: '<"d-flex justify-content-between align-items-center mb-3"Bf>rt<"d-flex justify-content-between align-items-center mt-3"ip>',
        buttons: [
            {
                extend: 'excel',
                text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                className: 'btn btn-success btn-sm',
                title: report.name || 'Reporte',
                exportOptions: {
                    footer: true,
                    format: {
                        body: function (data, row, column, node) {
                            const val = dataTable.cell(row, column).data();
                            const isNumeric = !isNaN(parseFloat(val)) && isFinite(val) && dataTable.column(column).header().innerText.toLowerCase() !== 'id';
                            if (isNumeric) {
                                return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parseFloat(val));
                            }
                            return val;
                        }
                    }
                }
            },
            {
                extend: 'pdf',
                text: '<i class="bi bi-file-earmark-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm',
                title: report.name || 'Reporte',
                messageTop: function () {
                    const now = new Date();
                    const timestamp = now.getFullYear().toString() +
                        (now.getMonth() + 1).toString().padStart(2, '0') +
                        now.getDate().toString().padStart(2, '0') +
                        now.getHours().toString().padStart(2, '0') +
                        now.getMinutes().toString().padStart(2, '0');
                    const auditCode = murcielagoCipher(timestamp);
                    return 'ID Reporte: ' + currentReportId + ' | Verificación: ' + auditCode + ' | Generado: ' + now.toLocaleString();
                },
                exportOptions: {
                    footer: true,
                    format: {
                        body: function (data, row, column, node) {
                            const val = dataTable.cell(row, column).data();
                            const isNumeric = !isNaN(parseFloat(val)) && isFinite(val) && dataTable.column(column).header().innerText.toLowerCase() !== 'id';
                            if (isNumeric) {
                                return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parseFloat(val));
                            }
                            return val;
                        }
                    }
                },
                customize: function (doc) {
                    const report = reportsCache[currentReportId] || {};
                    let groupCfg = { groupCol: '', sumCols: '' };
                    try { groupCfg = JSON.parse(report.grouping_config || '{}'); } catch (e) { }
                    const sumCols = groupCfg.sumCols ? groupCfg.sumCols.split(',').map(s => s.trim()) : [];

                    const formatQty = (val) => {
                        return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);
                    };

                    if (groupCfg.groupCol && doc.content[1].table.body.length > 1) {
                        const colIdx = columns.indexOf(groupCfg.groupCol);
                        if (colIdx !== -1) {
                            const body = doc.content[1].table.body;
                            const newBody = [body[0]]; // Header
                            let activeGroup = null;
                            let groupRows = [];

                            const insertSubtotal = (groupName, rows) => {
                                if (sumCols.length === 0) return;
                                let subtotalRow = [{ text: 'Subtotal ' + groupName, bold: true, fillColor: '#fafafa' }];
                                for (let c = 1; c < columns.length; c++) {
                                    if (sumCols.indexOf(columns[c]) !== -1) {
                                        let sum = rows.reduce((a, b) => {
                                            let val = b[c].text || b[c];
                                            return a + (parseFloat(val) || 0);
                                        }, 0);
                                        subtotalRow.push({ text: formatQty(sum), bold: true, fillColor: '#fafafa', alignment: 'right' });
                                    } else {
                                        subtotalRow.push({ text: '', fillColor: '#fafafa' });
                                    }
                                }
                                newBody.push(subtotalRow);
                            };

                            for (let i = 1; i < body.length; i++) {
                                const row = body[i];
                                const groupValue = (row[colIdx].text || row[colIdx] || '').toString();

                                if (groupValue !== activeGroup) {
                                    if (activeGroup !== null) insertSubtotal(activeGroup, groupRows);

                                    newBody.push([{
                                        text: groupValue,
                                        fillColor: '#f1f3f9',
                                        colSpan: columns.length,
                                        bold: true,
                                        margin: [0, 5, 0, 5]
                                    }].concat(Array(columns.length - 1).fill('')));

                                    activeGroup = groupValue;
                                    groupRows = [];
                                }
                                newBody.push(row);
                                groupRows.push(row);
                            }
                            // Last group subtotal
                            if (activeGroup !== null) insertSubtotal(activeGroup, groupRows);

                            doc.content[1].table.body = newBody;
                        }
                    }
                }
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Imprimir',
                className: 'btn btn-secondary btn-sm',
                title: report.name || 'Reporte',
                title: report.name || 'Reporte',
                messageTop: function () {
                    const now = new Date();
                    const timestamp = now.getFullYear().toString() +
                        (now.getMonth() + 1).toString().padStart(2, '0') +
                        now.getDate().toString().padStart(2, '0') +
                        now.getHours().toString().padStart(2, '0') +
                        now.getMinutes().toString().padStart(2, '0');
                    const auditCode = murcielagoCipher(timestamp);
                    return '<div class="text-center text-muted mb-3"><small>ID Reporte: ' + currentReportId + ' | Verificación: ' + auditCode + ' | Generado: ' + now.toLocaleString() + '</small></div>';
                },
                exportOptions: {
                    footer: true,
                    format: {
                        body: function (data, row, column, node) {
                            const val = dataTable.cell(row, column).data();
                            const isNumeric = !isNaN(parseFloat(val)) && isFinite(val) && dataTable.column(column).header().innerText.toLowerCase() !== 'id';
                            if (isNumeric) {
                                return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parseFloat(val));
                            }
                            return val;
                        }
                    }
                },
                customize: function (win) {
                    // SECURE: We no longer copy HTML from the table body via jQuery, 
                    // which prevents manual edits from leaking into the print view.
                    $(win.document.body).find('h1').css('text-align', 'center').css('font-size', '18pt');
                    $(win.document.body).find('table').addClass('compact').css('font-size', 'inherit');

                    const $printTable = $(win.document.body).find('table');

                    // ROBUST GROUPING FIX: Manually inject rowGroup headers and subtotals into the print window
                    const report = reportsCache[currentReportId] || {};
                    let groupCfg = { groupCol: '', sumCols: '' };
                    try { groupCfg = JSON.parse(report.grouping_config || '{}'); } catch (e) { }
                    const sumCols = groupCfg.sumCols ? groupCfg.sumCols.split(',').map(s => s.trim()) : [];

                    const formatQty = (val) => {
                        return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);
                    };

                    if (groupCfg.groupCol) {
                        const colIdx = columns.indexOf(groupCfg.groupCol);
                        if (colIdx !== -1) {
                            let lastGroupValue = null;
                            let groupRows = [];
                            let $rows = $printTable.find('tbody tr');

                            const insertSubtotalRow = ($afterElement, groupName, rowsData) => {
                                if (sumCols.length === 0) return;
                                let $subtotalTr = $('<tr class="dtrg-end"></tr>').append(`<td><strong>Subtotal ${groupName}</strong></td>`);
                                for (let c = 1; c < columns.length; c++) {
                                    if (sumCols.indexOf(columns[c]) !== -1) {
                                        let sum = rowsData.reduce((acc, val) => acc + (parseFloat(val[c]) || 0), 0);
                                        $subtotalTr.append(`<td class="text-end"><strong>${formatQty(sum)}</strong></td>`);
                                    } else {
                                        $subtotalTr.append('<td></td>');
                                    }
                                }
                                $afterElement.after($subtotalTr);
                                return $subtotalTr;
                            };

                            $rows.each(function (index) {
                                const $currentRow = $(this);
                                const rowData = $currentRow.find('td').map(function () { return $(this).text().trim().replace(/[^\d.-]/g, ''); }).get();
                                const rowValue = $currentRow.find('td').eq(colIdx).text().trim();

                                if (rowValue !== lastGroupValue) {
                                    if (lastGroupValue !== null) {
                                        const $prevRow = $currentRow.prev();
                                        insertSubtotalRow($prevRow, lastGroupValue, groupRows);
                                    }

                                    $currentRow.before(`<tr class="dtrg-group"><td colspan="${columns.length}">${rowValue}</td></tr>`);
                                    lastGroupValue = rowValue;
                                    groupRows = [];
                                }
                                groupRows.push(rowData);

                                // Last row subtotal
                                if (index === $rows.length - 1) {
                                    insertSubtotalRow($currentRow, rowValue, groupRows);
                                }
                            });
                        }
                    }

                    // ROBUST FOOTER FIX: Manually copy the footer content from the actual table
                    const footerHtml = $('#resultsTable tfoot').html();
                    if (footerHtml && footerHtml.trim() !== "") {
                        $printTable.find('tfoot').remove(); // Remove empty tfoot if any
                        $printTable.append('<tfoot>' + footerHtml + '</tfoot>');
                    }
                    // Force display of the footer which contains the totals
                    $(win.document.body).find('table tfoot').show();

                    const style = `
                        <style>
                            tr.dtrg-group td { background-color: #f8f9fc !important; font-weight: bold !important; border-top: 2px solid #ccc !important; padding: 10px !important; }
                            tr.dtrg-end td { background-color: #ffffff !important; font-weight: bold !important; border-bottom: 2px solid #eee !important; text-align: right !important; }
                            tr.dtrg-end td:first-child { text-align: left !important; }
                            table.dataTable.compact tbody tr td { padding: 4px 8px !important; }
                            .text-end { text-align: right !important; }
                            /* Ensure footer is bold and has a top border for visibility */
                            tfoot tr th, tfoot tr td { 
                                border-top: 2px solid #333 !important; 
                                font-weight: bold !important; 
                                font-size: 1.1em !important;
                                padding: 8px !important;
                                color: #000 !important;
                                text-align: right !important;
                            }
                            @media print {
                                tfoot { display: table-footer-group !important; }
                            }
                        </style>`;
                    $(win.document.head).append(style);
                }
            }
        ],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
        },
        footerCallback: function (row, data, start, end, display) {
            const api = this.api();
            if (sumCols.length === 0) return;

            const formatQty = (val) => {
                return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);
            };

            // Clear footer first
            $(api.columns().footer()).html('');

            columns.forEach((col, index) => {
                if (sumCols.indexOf(col) !== -1) {
                    const total = api.column(index).data().reduce((a, b) => (parseFloat(a) || 0) + (parseFloat(b) || 0), 0);
                    $(api.column(index).footer()).html('<strong>' + formatQty(total) + '</strong>').addClass('text-end');
                } else if (index === 0) {
                    $(api.column(index).footer()).html('<strong>TOTAL GENERAL</strong>');
                }
            });
        }
    });

    $('#resultsCard').removeClass('d-none');
}

function renderChart(data, columns) {
    if (data.length === 0) {
        $('#chartCard').addClass('d-none');
        return;
    }

    const report = reportsCache[currentReportId] || {};
    let groupCfg = { chartLabelCol: '', chartValueCol: '' };
    try { groupCfg = JSON.parse(report.grouping_config || '{}'); } catch (e) { }

    let labelIndex = -1;
    let dataIndex = -1;

    if (!data[0]) {
        $('#chartCard').addClass('d-none');
        return;
    }
    const firstRowValues = Object.values(data[0]);

    if (groupCfg.chartValueCol) {
        dataIndex = columns.indexOf(groupCfg.chartValueCol);
    }
    if (dataIndex === -1) {
        for (let i = firstRowValues.length - 1; i >= 0; i--) {
            if (!isNaN(parseFloat(firstRowValues[i])) && isFinite(firstRowValues[i]) && columns[i].toLowerCase() !== 'id') {
                dataIndex = i;
                break;
            }
        }
    }

    if (dataIndex === -1) {
        $('#chartCard').addClass('d-none');
        return;
    }

    if (groupCfg.chartLabelCol) {
        labelIndex = columns.indexOf(groupCfg.chartLabelCol);
    }
    if (labelIndex === -1) {
        const descriptiveKeywords = ['nombre', 'name', 'producto', 'product', 'cliente', 'customer', 'descripcion', 'description', 'categoria', 'category'];
        for (let i = 0; i < columns.length; i++) {
            const colLower = columns[i].toLowerCase();
            if (descriptiveKeywords.some(kw => colLower.includes(kw))) {
                labelIndex = i;
                break;
            }
        }
        if (labelIndex === -1) {
            labelIndex = 0;
            for (let i = 0; i < firstRowValues.length; i++) {
                if (isNaN(parseFloat(firstRowValues[i]))) {
                    labelIndex = i;
                    break;
                }
            }
        }
    }

    const labels = data.map(row => Object.values(row)[labelIndex]);
    const values = data.map(row => parseFloat(Object.values(row)[dataIndex]));
    const datasetLabel = columns[dataIndex];

    $('#chartCard').removeClass('d-none');
    const ctx = document.getElementById('reportChart').getContext('2d');
    if (reportChart) reportChart.destroy();

    reportChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: labelIndex !== -1 ? (columns[dataIndex] || 'Valor') : 'Valor',
                data: values,
                backgroundColor: APP_THEME.primaryLight,
                borderColor: APP_THEME.primary,
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                title: { display: true, text: 'Tendencia: ' + (columns[dataIndex] || 'Valores') }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

function saveReport() {
    let data = {};
    const form = $('#editorForm').serializeArray();
    form.forEach(item => data[item.name] = item.value);

    data.grouping_config = JSON.stringify({
        groupCol: $('#editGroupCol').val(),
        sumCols: $('#editSumCols').val(),
        chartLabelCol: $('#editChartLabelCol').val(),
        chartValueCol: $('#editChartValueCol').val()
    });

    data.phpscript2 = $('#editPhp2').val();
    data.post_action_code = $('#editPostActionCode').val();
    data.post_action_params = $('#editPostActionParams').val();
    data.is_automatic = $('#editIsAutomatic').is(':checked') ? 1 : 0;
    data.is_view = $('#editIsView').is(':checked') ? 1 : 0;
    data.is_active = $('#editIsActive').is(':checked') ? 1 : 0;
    data.cron_interval_minutes = $('#editCronInterval').val() || 60;

    const aclArray = $('#editAclView').val().split(',').map(s => s.trim()).filter(s => s !== '');
    data.acl_view = aclArray.length > 0 ? JSON.stringify(aclArray) : null;

    try {
        JSON.parse(data.columns_json);
        JSON.parse(data.parameters_json);
    } catch (e) {
        Swal.fire('Error JSON', "Error en el formato JSON: " + e.message, 'error');
        return;
    }

    $.ajax({
        url: 'api/index.php?action=save',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(data),
        success: function (resp) {
            try {
                if (resp.success) {
                    const modalEl = document.getElementById('editorModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide(); else $(modalEl).modal('hide');

                    Swal.fire({
                        icon: 'success',
                        title: 'Guardado',
                        text: 'Reporte guardado correctamente',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    loadReports();
                } else {
                    Swal.fire('Error', 'Error: ' + resp.error, 'error');
                }
            } catch (ex) {
                console.error(ex);
                Swal.fire('Error Interno', 'Error JS: ' + ex.message, 'error');
            }
        },
        error: function (err) {
            Swal.fire('Error', 'Error de conexión guardar', 'error');
            console.error(err);
        }
    });
}

function deleteReport(id) {
    $.ajax({
        url: 'api/index.php?action=delete',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ id: id }),
        success: function (resp) {
            try {
                if (resp.success) {
                    Swal.fire('Eliminado', 'El reporte ha sido eliminado.', 'success');
                    loadReports();
                    $('#btnEditReport').prop('disabled', true);
                    $('#btnDeleteReport').prop('disabled', true);
                    $('#filterCard').addClass('d-none');
                    $('#chartCard').addClass('d-none');
                } else {
                    Swal.fire('Error', 'Error: ' + resp.error, 'error');
                }
            } catch (ex) {
                console.error(ex);
                Swal.fire('Error Interno', 'Error JS: ' + ex.message, 'error');
            }
        },
        error: function (err) {
            Swal.fire('Error', 'Error de conexión borrar', 'error');
            console.error(err);
        }
    });
}

function switchToPivot() {
    $('#pivotOutput').html('<div class="text-center p-5"><span class="spinner-border text-primary"></span><br>Cargando todos los datos para análisis...</div>');
    $('#viewReportContainer').addClass('d-none');
    $('#viewPivotContainer').removeClass('d-none');

    const payload = {
        report_id: currentReportId,
        filters: currentFilters,
        draw: 1,
        start: 0,
        length: -1,
        search: { value: '' }
    };

    $.ajax({
        url: 'api/index.php?action=execute',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        success: function (resp) {
            if (resp.success) renderPivot(resp.data);
            else $('#pivotOutput').html('<div class="alert alert-danger">Error cargando datos: ' + resp.error + '</div>');
        },
        error: function (err) {
            $('#pivotOutput').html('<div class="alert alert-danger">Error de conexión</div>');
        }
    });
}

function switchToReport() {
    $('#viewPivotContainer').addClass('d-none');
    $('#viewReportContainer').removeClass('d-none');
}

function renderPivot(data) {
    if (!data || data.length === 0) {
        $('#pivotOutput').html('<div class="alert alert-warning">No hay datos para analizar.</div>');
        return;
    }

    const cleanData = data.map(row => {
        let newRow = {};
        for (let key in row) {
            let val = row[key];
            if (!isNaN(parseFloat(val)) && isFinite(val)) newRow[key] = parseFloat(val);
            else newRow[key] = val;
        }
        return newRow;
    });

    currentPivotData = cleanData;
    let targetAggregator = "Cuenta";
    let targetVals = [];

    if (cleanData.length > 0) {
        let keys = Object.keys(cleanData[0]);
        const sumKeywords = ['total', 'precio', 'price', 'amount', 'venta', 'costo', 'importe', 'saldo'];
        for (let key of keys) {
            let lowerKey = key.toLowerCase();
            if (sumKeywords.some(kw => lowerKey.includes(kw))) {
                if (typeof cleanData[0][key] === 'number') {
                    targetAggregator = "Suma";
                    targetVals = [key];
                    break;
                }
            }
        }
    }

    const plotlyRenderers = $.pivotUtilities.plotly_renderers;
    const esRenderers = $.pivotUtilities.locales.es.renderers;
    const renderers = Object.assign({}, esRenderers, {
        "Gráfico de Barras": plotlyRenderers["Bar Chart"],
        "Gráfico de Barras Apiladas": plotlyRenderers["Stacked Bar Chart"],
        "Gráfico de Líneas": plotlyRenderers["Line Chart"],
        "Gráfico de Área": plotlyRenderers["Area Chart"],
        "Gráfico de Dispersión": plotlyRenderers["Scatter Chart"],
        "Gráfico Circular (Donut)": plotlyRenderers["Multiple Pie Chart"]
    });

    $("#pivotOutput").pivotUI(cleanData, {
        renderers: renderers,
        aggregatorTemplates: $.pivotUtilities.locales.es.aggregatorTemplates,
        localeStrings: $.pivotUtilities.locales.es.localeStrings,
        rendererName: "Tabla",
        aggregatorName: targetAggregator,
        vals: targetVals,
        onRefresh: function (config) {
            lastPivotConfig = config;
            const btn = $('#btnPivotGraph');
            if (config.rendererName === "Tabla" || config.rendererName === "Tabla Heatmap") {
                btn.html('<i class="bi bi-bar-chart-fill me-1"></i> Graficar');
                btn.removeClass('btn-secondary').addClass('btn-warning');
            } else {
                btn.html('<i class="bi bi-table me-1"></i> Ver Tabla');
                btn.removeClass('btn-warning').addClass('btn-secondary');
            }
        }
    });
}

function togglePivotChart() {
    if (!currentPivotData || !lastPivotConfig) return;
    const currentRenderer = lastPivotConfig.rendererName;
    let newRenderer = "Gráfico de Barras";
    if (currentRenderer === "Gráfico de Barras" || (currentRenderer !== "Tabla" && currentRenderer !== "Tabla Heatmap")) newRenderer = "Tabla";

    const options = { ...lastPivotConfig, rendererName: newRenderer };
    const plotlyRenderers = $.pivotUtilities.plotly_renderers;
    const esRenderers = $.pivotUtilities.locales.es.renderers;
    options.renderers = Object.assign({}, esRenderers, {
        "Gráfico de Barras": plotlyRenderers["Bar Chart"],
        "Gráfico de Barras Apiladas": plotlyRenderers["Stacked Bar Chart"],
        "Gráfico de Líneas": plotlyRenderers["Line Chart"],
        "Gráfico de Área": plotlyRenderers["Area Chart"],
        "Gráfico de Dispersión": plotlyRenderers["Scatter Chart"],
        "Gráfico Circular (Donut)": plotlyRenderers["Multiple Pie Chart"]
    });

    $("#pivotOutput").pivotUI(currentPivotData, options, true);
}

// AI Functions (Deprecated legacy config replaced by ConnectionsManager)


// AI Generation from Modal
$(document).on('click', '#btnAiAssist', function () {
    $('#aiUserPrompt').val('');
    new bootstrap.Modal('#aiPromptModal').show();
});

$(document).on('click', '#btnRunAiGeneration', function () {
    const prompt = $('#aiUserPrompt').val();
    if (!prompt) return;

    const promptModalEl = document.getElementById('aiPromptModal');
    const promptModal = bootstrap.Modal.getInstance(promptModalEl);
    if (promptModal) promptModal.hide(); else $(promptModalEl).modal('hide');

    const btn = $('#btnAiAssist');
    const originalText = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Generando...');

    $.ajax({
        url: 'api/ai_service.php?action=generate',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            prompt: prompt,
            mode: 'sql',
            provider: localStorage.getItem('ai_last_provider') || 'openai',
            current_config: {
                code: $('#editCode').val(),
                name: $('#editName').val(),
                description: $('#editDesc').val(),
                sql_query: $('#editSql').val(),
                columns_json: $('#editCols').val(),
                parameters_json: $('#editParams').val(),
                php_script: $('#editPhp').val()
            }
        }),
        success: function (resp) {
            if (resp.success) {
                const data = resp.data;
                console.log("DEBUG IA Response Data:", data);
                $('#editCode').val(data.code || ('REP_' + Math.floor(Math.random() * 10000)));
                $('#editName').val(data.name || 'Reporte Generado');
                $('#editDesc').val(data.description || 'Generado por IA');
                $('#editSql').val(data.sql_query);
                $('#editCols').val(typeof data.columns_json === 'string' ? data.columns_json : JSON.stringify(data.columns_json || []));
                $('#editParams').val(typeof data.parameters_json === 'string' ? data.parameters_json : JSON.stringify(data.parameters_json || []));
                if (data.php_script) $('#editPhp').val(data.php_script);
                if (data.phpscript2) $('#editPhp2').val(data.phpscript2);
                if (data.post_action_code) $('#editPostActionCode').val(data.post_action_code);
                if (data.post_action_params) {
                    const pap = typeof data.post_action_params === 'string' ? data.post_action_params : JSON.stringify(data.post_action_params, null, 2);
                    $('#editPostActionParams').val(pap);
                }
                Swal.fire({ icon: 'success', title: 'Generado', text: 'Reporte generado/editado por IA.' });
            } else Swal.fire('Error IA', resp.error, 'error');
        },
        complete: function () { btn.prop('disabled', false).html(originalText); }
    });
});

function murcielagoCipher(text) {
    const map = {
        '0': 'M', '1': 'U', '2': 'R', '3': 'C', '4': 'I',
        '5': 'E', '6': 'L', '7': 'A', '8': 'G', '9': 'O'
    };
    return text.toString().split('').map(char => map[char] || char).join('');
}

function initRobotSync() {
    // Escuchar cambios desde otras pestañas
    window.addEventListener('storage', (e) => {
        if (e.key === ROBOT_STATE_KEY) {
            const active = e.newValue === '1';
            $('#masterRobotSwitch').prop('checked', active);
            syncRobotUI(active);
        }
    });

    // Estado inicial
    const initialState = localStorage.getItem(ROBOT_STATE_KEY) === '1';
    $('#masterRobotSwitch').prop('checked', initialState);
    syncRobotUI(initialState);

    // Bucle de monitoreo (Corazón/Heartbeat)
    setInterval(() => {
        const shouldBeActive = localStorage.getItem(ROBOT_STATE_KEY) === '1';
        if (!shouldBeActive) {
            stopRobotInterval();
            return;
        }

        const now = Date.now();
        const heartbeat = parseInt(localStorage.getItem(ROBOT_HEARTBEAT_KEY) || 0);

        // Si no hay latido o es viejo (>12s), este tab intenta ser el Master
        // O si ya somos el master, renovamos el latido
        if (isRobotMaster || (now - heartbeat > 12000)) {
            localStorage.setItem(ROBOT_HEARTBEAT_KEY, now.toString());

            if (!isRobotMaster) {
                console.log("Robot: Esta pestaña ha tomado el control (Master Role).");
                isRobotMaster = true;
                startRobotInterval();
            }
        } else {
            // Hay otro master activo y es joven
            if (isRobotMaster) {
                console.log("Robot: Cediendo control a otra pestaña.");
                stopRobotInterval();
            }
        }
    }, 5000);
}

function syncRobotUI(active) {
    const label = $('#robotStatusLabel');
    if (active) {
        label.html('<i class="bi bi-robot me-1 anim-robot"></i> Robot: ENCENDIDO (Activo)');
        label.addClass('text-warning').removeClass('text-white');
    } else {
        label.html('<i class="bi bi-robot me-1"></i> Robot: APAGADO');
        label.addClass('text-white').removeClass('text-warning');
    }
}

function startRobotInterval() {
    if (robotInterval) clearInterval(robotInterval);
    // Ejecución inmediata al tomar el mando
    runRobotStep();
    // Programar cada minuto
    robotInterval = setInterval(runRobotStep, 60000);
}

function stopRobotInterval() {
    isRobotMaster = false;
    if (robotInterval) {
        clearInterval(robotInterval);
        robotInterval = null;
    }
}

function toggleRobot(active) {
    localStorage.setItem(ROBOT_STATE_KEY, active ? '1' : '0');
    // Si se apaga manualmente, limpiamos el heartbeat para que otro pueda tomarlo pronto si se re-enciende
    if (!active) localStorage.removeItem(ROBOT_HEARTBEAT_KEY);
    syncRobotUI(active);
}

function runRobotStep() {
    // Solo el master debería llegar aquí idealmente, pero doble check
    if (!isRobotMaster) return;

    console.log('Robot: Verificando reportes automáticos...');
    $.getJSON('api/robot.php', function (resp) {
        if (resp.success && resp.executed_count > 0) {
            console.log('Robot: Se ejecutaron ' + resp.executed_count + ' reportes.');
            loadReports(); // Refrescar sellos de tiempo
        }
    });
}

function processReport() {
    if (!currentReportId) return;
    Swal.fire({
        title: '¿Procesar Reporte?',
        text: 'Esto ejecutará el script de post-procesamiento.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, Procesar'
    }).then((result) => {
        if (result.isConfirmed) {
            const btn = $('#btnProcessReport');
            const originalText = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Procesando...');

            const report = reportsCache[currentReportId];
            if (!report) {
                btn.prop('disabled', false).html(originalText);
                Swal.fire('Error', 'No se pudo encontrar la configuración del reporte en caché.', 'error');
                return;
            }

            // Collect all data from the table (including edits in the DOM)
            const editedData = [];
            const headerCols = [];
            $('#tableHeaderRow th').each(function () { headerCols.push($(this).text()); });

            const keys = Object.keys(report.data_sample || {});
            const sampleRow = dataTable.row(0).data();
            const rowKeys = sampleRow ? Object.keys(sampleRow) : [];

            $('#resultsTable tbody tr').each(function () {
                const rowData = {};
                const cells = $(this).find('td');
                if (cells.length === rowKeys.length && rowKeys.length > 0) {
                    rowKeys.forEach((key, index) => {
                        rowData[key] = $(cells[index]).text().trim();
                    });
                    editedData.push(rowData);
                }
            });

            $.ajax({
                url: 'api/index.php?action=process',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    report_id: currentReportId,
                    filters: currentFilters || {},
                    data: editedData
                }),
                success: function (resp) {
                    if (resp.success) Swal.fire({ icon: 'success', title: 'Procesado', html: resp.message || 'Acción ejecutada' });
                    else Swal.fire('Error', resp.error || 'Error al procesar', 'error');
                },
                complete: function () { btn.prop('disabled', false).html(originalText); }
            });
        }
    });
}

function switchMainTab(tab) {
    // Hide everything
    $('#reportsView, #scenariosView, #tablesView, #db_connectionsView, #servicesView, #actionsView, #usersView, #logsView').addClass('d-none');
    $('.nav-link').removeClass('active');

    if (tab === 'reports') {
        $('#reportsView').removeClass('d-none');
        $('a[onclick*="reports"]').addClass('active');
    }
    else if (tab === 'scenarios') {
        $('#scenariosView').removeClass('d-none');
        $('a[onclick*="scenarios"]').addClass('active');
        if (typeof grid !== 'undefined' && grid) {
            setTimeout(() => { window.dispatchEvent(new Event('resize')); }, 100);
        }
    }
    else if (tab === 'users') {
        $('#usersView').removeClass('d-none');
        $('a[onclick*="users"]').addClass('active');
        if (typeof UsersManager !== 'undefined') UsersManager.load();
    }
    else if (tab === 'tables') {
        $('#tablesView').removeClass('d-none');
        $('a[onclick*="tables"]').addClass('active');
        if (typeof loadCustomTables === 'function') loadCustomTables();
    }
    else if (tab === 'db_connections') {
        $('#db_connectionsView').removeClass('d-none');
        $('a[onclick*="db_connections"]').addClass('active');
        if (typeof DbConnectionsManager !== 'undefined') DbConnectionsManager.loadList();
    }
    else if (tab === 'services') {
        $('#servicesView').removeClass('d-none');
        $('a[onclick*="services"]').addClass('active');
        if (typeof ConnectionsManager !== 'undefined') ConnectionsManager.loadList();
    }
    else if (tab === 'actions') {
        $('#actionsView').removeClass('d-none');
        $('a[onclick*="actions"]').addClass('active');
        if (typeof loadActions === 'function') loadActions();
    }
    else if (tab === 'logs') {
        $('#logsView').removeClass('d-none');
        $('a[onclick*="logs"]').addClass('active');
        if (typeof LogsManager !== 'undefined') LogsManager.load();
    }
}

/**
 * ADMIN SECURITY MODE
 */
let isAdmin = false;

function checkAdminStatus() {
    $.get('api/auth.php?action=status', function (resp) {
        isAdmin = resp.isAdmin;
        updateAdminUI();
    });
}

function updateAdminUI() {
    if (isAdmin) {
        $('body').addClass('is-admin');
        $('#adminToggleBtn').html('<i class="bi bi-shield-check"></i>').addClass('btn-success').removeClass('btn-outline-light');
    } else {
        $('body').removeClass('is-admin');
        $('#adminToggleBtn').html('<i class="bi bi-shield-lock"></i>').addClass('btn-outline-light').removeClass('btn-success');

        // If current tab is admin-only, switch to reports
        const currentTab = $('.nav-link.active').attr('onclick');
        if (currentTab && (currentTab.includes('tables') || currentTab.includes('db_connections') || currentTab.includes('services') || currentTab.includes('actions'))) {
            switchMainTab('reports');
        }
    }
    // Always reload reports to reflect admin view (all) vs user view (active only)
    loadReports();
}

function toggleAdminMode() {
    if (isAdmin) {
        // Logout
        $.get('api/auth.php?action=logout', function () {
            isAdmin = false;
            updateAdminUI();
            Swal.fire({ icon: 'info', title: 'Modo Administrador', text: 'Sesión cerrada correctamente.', timer: 1500 });
        });
    } else {
        // Login prompt
        Swal.fire({
            title: 'Acceso Administrador',
            input: 'password',
            inputLabel: 'Ingrese la Contraseña Maestra',
            inputPlaceholder: 'Contraseña...',
            showCancelButton: true,
            confirmButtonText: 'Entrar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                $.ajax({
                    url: 'api/auth.php?action=login',
                    method: 'POST',
                    data: JSON.stringify({ password: result.value }),
                    success: function (resp) {
                        if (resp.success) {
                            isAdmin = true;
                            updateAdminUI();
                            Swal.fire({ icon: 'success', title: 'Acceso Correcto', text: 'Has entrado en Modo Administrador', timer: 1500 });
                        } else {
                            Swal.fire('Error', resp.error, 'error');
                        }
                    }
                });
            }
        });
    }
}

function openPasswordChangeModal() {
    $('#passwordChangeForm')[0].reset();
    new bootstrap.Modal('#passwordChangeModal').show();
}

function changePassword() {
    const oldPass = $('#oldPassword').val();
    const newPass = $('#newPassword').val();
    const confirmPass = $('#confirmPassword').val();

    if (!oldPass || !newPass || !confirmPass) {
        Swal.fire('Error', 'Todos los campos son obligatorios', 'error');
        return;
    }

    if (newPass !== confirmPass) {
        Swal.fire('Error', 'La nueva contraseña y la confirmación no coinciden', 'error');
        return;
    }

    if (newPass.length < 4) {
        Swal.fire('Error', 'La nueva contraseña debe tener al menos 4 caracteres', 'error');
        return;
    }

    $.ajax({
        url: 'api/auth.php?action=change_password',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            old_password: oldPass,
            new_password: newPass
        }),
        success: function (resp) {
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('passwordChangeModal')).hide();
                Swal.fire('Éxito', 'Contraseña actualizada correctamente', 'success');
            } else {
                Swal.fire('Error', resp.error || 'No se pudo cambiar la contraseña', 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'Error de comunicación con el servidor', 'error');
        }
    });
}

/**
 * Genera un link público para el reporte con los filtros actuales
 */
function shareReport() {
    if (!currentReportId) return;

    Swal.fire({
        title: 'Generando link de compartido...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: 'api/index.php?action=share_save',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            report_id: currentReportId,
            filters: currentFilters
        }),
        success: function (resp) {
            if (resp.success) {
                const baseUrl = window.location.origin + window.location.pathname;
                const shareUrl = `${baseUrl}?token=${resp.token}`;

                Swal.fire({
                    title: '¡Reporte Publicado!',
                    html: `
                        <p>Cualquier persona con este link podrá ver los resultados con los filtros actuales:</p>
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

function copyShareUrl() {
    const input = document.getElementById('shareUrlInput');
    if (input) {
        input.select();
        document.execCommand('copy');
        Toast.fire({ icon: 'success', title: 'Link copiado al portapapeles' });
    }
}

/**
 * Carga un reporte compartido mediante un token
 */
function loadSharedReport(token) {
    Swal.fire({
        title: 'Cargando reporte compartido...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.getJSON(`api/index.php?action=share_get&token=${token}`, function (resp) {
        if (resp.success) {
            const shared = resp.data;
            currentReportId = shared.id; // Map r.id (since we query * from reports)

            // 1. Cache Report Definition
            reportsCache[currentReportId] = shared;

            // 2. Render Filters logic
            renderFilters(shared);
            $('#filterCard').removeClass('d-none');

            try {
                // Use shared.shared_filters from the alias we created in PHP
                currentFilters = JSON.parse(shared.shared_filters || '{}');
            } catch (e) {
                currentFilters = {};
            }

            // 3. Pre-fill Filters Form
            if (Object.keys(currentFilters).length > 0) {
                for (const [key, val] of Object.entries(currentFilters)) {
                    // Check for array inputs (date ranges, multiple selects)
                    const input = $(`[name="${key}"], [name="${key}[]"]`);
                    if (input.length > 0) {
                        if (Array.isArray(val) && input.length > 1) {
                            // Handle date range pair
                            input.each(function (index) {
                                if (val[index]) $(this).val(val[index]);
                            });
                        } else {
                            input.val(val);
                        }
                    }
                }
            }

            // 4. Update UI Title
            $('title').text(shared.name + ' - Compartido');
            $('#reportDescription').html(`<span class="badge bg-info me-2">Modo Público</span> ${shared.description || ''}`);

            // 5. Hide "Actions" that are admin-only/confusing
            // We ensure "Share" button is hidden in shared mode to prevent recursion
            // Filter out 'Compartir' specifically.
            // We use a small timeout to ensure DOM is ready if buttons are dynamic, 
            // though they should be static in the HTML or rendered by renderTable?
            // Actually renderTable rewrites the table but buttons are in .card-tools header.
            $('.card-tools button').removeClass('d-none'); // Reset
            $('.card-tools button').each(function () {
                if ($(this).text().includes('Compartir')) {
                    $(this).addClass('d-none');
                }
            });

            // 6. Execute Report
            executeReport();

            Swal.close();

        } else {
            Swal.fire('Error', resp.error, 'error');
        }
    });
}
