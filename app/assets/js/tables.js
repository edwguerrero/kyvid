/**
 * Custom Data Tables Manager
 * Handles creating tables, editing schema, and managing data with Excel import.
 */

let currentTableName = null;
let currentTableSchema = [];
let dataTableInstance = null;

$(document).ready(function () {

    $('#importExcelInput').on('change', function (e) {
        if (!currentTableName) return;
        const file = e.target.files[0];
        if (!file) return;
        importFromExcel(file);
        $(this).val('');
    });
});



/**
 * 1. LIST TABLES
 */
function loadCustomTables() {
    $('#tablesList').html('<div class="text-center p-3 text-muted small"><span class="spinner-border spinner-border-sm"></span></div>');

    $.get('api/tables.php?action=list', function (resp) {
        if (resp.success) {
            const list = $('#tablesList');
            list.empty();

            if (resp.data.length === 0) {
                list.html('<div class="text-center p-3 text-muted small">No hay tablas creadas.</div>');
                return;
            }

            resp.data.forEach(t => {
                const activeClass = (currentTableName === t.table_name) ? 'active' : '';
                list.append(`
                    <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ${activeClass}" 
                        onclick="selectTable('${t.table_name}', this)">
                        <span class="font-monospace small text-truncate" style="max-width: 80%;">${t.table_name}</span>
                        <i class="bi bi-chevron-right small text-muted"></i>
                    </button>
                `);
            });
        }
    });
}

function selectTable(name, elem) {
    $('#tablesList .active').removeClass('active');
    $(elem).addClass('active');

    currentTableName = name;
    $('#currentTableName').text(name);
    $('#tableActions').removeClass('d-none');
    $('#tableDataToolbar').addClass('d-flex').removeClass('d-none');

    loadTableSchema(name, () => {
        initSearchToolbar(currentTableSchema);
        reloadTableData();
    });
}


/**
 * 2. SCHEMA & CREATE
 */
function openTableCreator() {
    $('#createTableModal').modal('show');
    $('#newTableName').val('');
    $('#newTableColumns').empty();
    addColPending();
}

function addColPending() {
    const id = Date.now();
    const html = `
        <tr id="col_row_${id}">
            <td><input type="text" class="form-control form-control-sm col-name" placeholder="nombre_campo"></td>
            <td>
                <select class="form-select form-select-sm col-type">
                    <option value="string">Texto (String)</option>
                    <option value="number">Número (Decimal)</option>
                    <option value="boolean">Booleano (Sí/No)</option>
                    <option value="datetime">Fecha/Hora</option>
                </select>
            </td>
            <td>
                <button class="btn btn-sm btn-outline-danger border-0" onclick="$('#col_row_${id}').remove()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        </tr>
    `;
    $('#newTableColumns').append(html);
}

function createCustomTable() {
    const nameRaw = $('#newTableName').val().trim().toLowerCase();
    if (!nameRaw) {
        Swal.fire("Error", "Ingrese un nombre para la tabla.", "error");
        return;
    }

    const name = nameRaw.startsWith('tb_') ? nameRaw : 'tb_' + nameRaw;
    const columns = [];
    let error = false;

    $('#newTableColumns tr').each(function () {
        const colName = $(this).find('.col-name').val().trim();
        const colType = $(this).find('.col-type').val();

        if (!colName) return;

        if (!/^[a-z0-9_]+$/.test(colName)) {
            Swal.fire("Error", `Nombre de columna inválido: ${colName}. Solo minúsculas, números y guión bajo.`, "error");
            error = true;
            return false;
        }

        columns.push({ name: colName, type: colType });
    });

    if (error) return;
    if (columns.length === 0) {
        Swal.fire("Error", "Agregue al menos una columna.", "error");
        return;
    }

    $.ajax({
        url: 'api/tables.php?action=create_table',
        method: 'POST',
        data: JSON.stringify({ name: name, columns: columns }),
        contentType: 'application/json',
        success: function (resp) {
            if (resp.success) {
                $('#createTableModal').modal('hide');
                loadCustomTables();
                Swal.fire("Éxito", "Tabla creada correctamente.", "success");
            } else {
                Swal.fire("Error", resp.error, "error");
            }
        }
    });
}

function deleteCurrentTable() {
    if (!currentTableName) return;

    Swal.fire({
        title: '¿Eliminar Tabla?',
        text: `Se eliminará la tabla "${currentTableName}" y TODOS sus datos. Esta acción no se puede deshacer.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/tables.php?action=delete_table',
                method: 'POST',
                data: JSON.stringify({ name: currentTableName }),
                success: function (resp) {
                    if (resp.success) {
                        currentTableName = null;
                        $('#currentTableName').text('Seleccione una tabla');
                        $('#tableActions').addClass('d-none');
                        $('#customTableGrid').empty();
                        loadCustomTables();
                        Swal.fire('Eliminada', 'La tabla ha sido eliminada.', 'success');
                    } else {
                        Swal.fire('Error', resp.error, 'error');
                    }
                }
            });
        }
    });
}

/**
 * 2b. STRUCTURE EDITOR (NEW)
 */
function openColumnEditor() {
    if (!currentTableName) return;

    // Simple alert for now, can be expanded to full editor later
    // or re-use create modal in "view mode"

    let schemaHtml = '<ul class="list-group text-start">';
    currentTableSchema.forEach(col => {
        schemaHtml += `<li class="list-group-item d-flex justify-content-between align-items-center">
            <span class="font-monospace">${col.Field}</span>
            <span class="badge bg-secondary">${col.Type}</span>
        </li>`;
    });
    schemaHtml += '</ul>';

    Swal.fire({
        title: `Estructura: ${currentTableName}`,
        html: schemaHtml,
        width: '600px'
    });
}


/**
 * 3. DATA MANAGEMENT
 */
function loadTableSchema(tableName, callback) {
    $.get(`api/tables.php?action=get_schema&name=${tableName}`, function (resp) {
        if (resp.success) {
            currentTableSchema = resp.data;
            if (callback) callback();
        }
    });
}

function reloadTableData() {
    if (!currentTableName) return;

    $('#tableLoading').removeClass('d-none');

    if (dataTableInstance) {
        dataTableInstance.destroy();
        $('#customTableGrid').empty();
    }

    $.ajax({
        url: 'api/tables.php?action=get_data',
        method: 'POST',
        data: JSON.stringify({ name: currentTableName }),
        success: function (resp) {
            $('#tableLoading').addClass('d-none');
            if (resp.success) {
                renderDataTable(resp.data);
                $('#rowCountBadge').text(`${resp.data.length} filas`);
            }
        }
    });
}

function renderDataTable(data) {
    if (!currentTableSchema || currentTableSchema.length === 0) return;

    const columns = currentTableSchema.map(col => {
        return {
            data: col.Field,
            title: col.Field,
            defaultContent: "", // Handle nulls
            render: function (data, type, row) {
                return data ? data : "";
            }
        };
    });

    columns.push({
        data: null,
        title: "Acciones",
        orderable: false,
        width: "80px",
        render: function (data, type, row) {
            // Store row data in data attribute to avoid quote escaping issues in onclick
            return `
                <button class='btn btn-xs btn-outline-primary btn-edit-row' data-row='${JSON.stringify(row).replace(/'/g, "&#39;")}'><i class='bi bi-pencil'></i></button>
                <button class='btn btn-xs btn-outline-danger' onclick='deleteRow(${row.id})'><i class='bi bi-trash'></i></button>
            `;
        }
    });

    dataTableInstance = $('#customTableGrid').DataTable({
        data: data,
        columns: columns,
        responsive: true,
        dom: 'Brtip', // Removed 'f' (filtering input) to use custom toolbar
        buttons: ['excel', 'csv'],
        language: {
            "processing": "Procesando...",
            "lengthMenu": "Mostrar _MENU_ registros",
            "zeroRecords": "No se encontraron resultados",
            "emptyTable": "Ningún dato disponible en esta tabla",
            "info": "Mostrando registros del _START_ al _END_ de un total de _TOTAL_ registros",
            "infoEmpty": "Mostrando registros del 0 al 0 de un total de 0 registros",
            "infoFiltered": "(filtrado de un total de _MAX_ registros)",
            "search": "Buscar:",
            "infoThousands": ",",
            "loadingRecords": "Cargando...",
            "paginate": {
                "first": "Primero",
                "last": "Último",
                "next": "Siguiente",
                "previous": "Anterior"
            },
            "aria": {
                "sortAscending": ": Activar para ordenar la columna de manera ascendente",
                "sortDescending": ": Activar para ordenar la columna de manera descendente"
            }
        },
        destroy: true
    });

    // Bind click event via jQuery to handle complex data objects safely
    $('#customTableGrid').off('click', '.btn-edit-row').on('click', '.btn-edit-row', function () {
        const rowData = $(this).data('row');
        editRow(rowData);
    });
}

/**
 * 4. ROW CRUD
 */
function openRowEditor() {
    $('#rowEditId').val('');
    buildRowEditorFields();
    $('#rowEditorTitle').text('Nuevo Registro: ' + currentTableName);
    $('#rowEditorModal').modal('show');
}

function editRow(row) {
    $('#rowEditId').val(row.id);
    buildRowEditorFields(row);
    $('#rowEditorTitle').text('Editar Registro #' + row.id);
    $('#rowEditorModal').modal('show');
}

function buildRowEditorFields(data = {}) {
    const container = $('#rowEditorFields');
    container.empty();

    currentTableSchema.forEach(col => {
        const field = col.Field;
        if (field === 'id' || field === 'created_at' || field === 'updated_at') return;

        const type = col.Type.toLowerCase();
        let inputType = 'text';
        if (type.includes('int') || type.includes('decimal') || type.includes('float')) inputType = 'number';
        // Remove datetime-local restriction which might be buggy with empty values
        // if (type.includes('date') || type.includes('time')) inputType = 'datetime-local';

        const wrapper = $(`<div class="mb-3"></div>`);
        wrapper.append(`<label class="form-label small fw-bold">${field}</label>`);

        const input = $(`<input type="${inputType}" class="form-control" name="${field}">`);
        // Use .val() to safely set value without HTML escaping issues
        let val = data[field] || '';
        input.val(val);

        wrapper.append(input);
        container.append(wrapper);
    });
}

function saveRowData() {
    if (!currentTableName) return;

    const formData = $('#rowEditorForm').serializeArray();
    const row = {};
    formData.forEach(item => row[item.name] = item.value);

    $.ajax({
        url: 'api/tables.php?action=save_row',
        method: 'POST',
        data: JSON.stringify({ table: currentTableName, row: row }),
        success: function (resp) {
            if (resp.success) {
                $('#rowEditorModal').modal('hide');
                reloadTableData();
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 3000
                });
                Toast.fire({ icon: 'success', title: 'Guardado correctamente' });
            } else {
                Swal.fire("Error", resp.error, "error");
            }
        }
    });
}

function deleteRow(id) {
    Swal.fire({
        title: '¿Borrar?',
        text: "No podrás revertir esto.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, borrar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/tables.php?action=delete_row',
                method: 'POST',
                data: JSON.stringify({ table: currentTableName, id: id }),
                success: function (resp) {
                    if (resp.success) {
                        reloadTableData();
                        Swal.fire('Borrado', 'El registro ha sido eliminado.', 'success');
                    }
                }
            });
        }
    })
}

/**
 * 5. EXCEL IMPORT
 */
function importFromExcel(file) {
    const reader = new FileReader();

    reader.onload = function (e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, { type: 'array' });
        const firstSheetName = workbook.SheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];
        const json = XLSX.utils.sheet_to_json(worksheet, { raw: false });

        if (json.length === 0) {
            Swal.fire("Aviso", "El archivo parece vacío.", "info");
            return;
        }

        Swal.fire({
            title: 'Confirmar Importación',
            text: `Se encontraron ${json.length} filas. ¿Importar a "${currentTableName}"? coincidir columnas.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, importar'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#tableLoading').removeClass('d-none');

                $.ajax({
                    url: 'api/tables.php?action=import_data',
                    method: 'POST',
                    data: JSON.stringify({ table: currentTableName, rows: json }),
                    success: function (resp) {
                        if (resp.success) {
                            Swal.fire("Éxito", `Importación completada: ${resp.count} filas.`, "success");
                            reloadTableData();
                        } else {
                            Swal.fire("Error", resp.error, "error");
                            $('#tableLoading').addClass('d-none');
                        }
                    },
                    error: function (err) {
                        Swal.fire("Error", "Fallo de red o servidor.", "error");
                        $('#tableLoading').addClass('d-none');
                    }
                });
            }
        });
    };

    reader.readAsArrayBuffer(file);
}

/**
 * 6. ADVANCED SEARCH
 */
function initSearchToolbar(schema) {
    const select = $('#searchColumnSelect');
    select.empty();

    schema.forEach(col => {
        // Option value = column name (DataTables needs index, but name search is safer via API if we map it, or we loop to find index)
        // Let's use index based on schema position, aligning with renderDataTable columns
        // DESCRIBE order matches renderDataTable order.
        // BUT renderDataTable logic is: schema cols, then Actions.
        // So schema index i corresponds to DataTable column i.
        const idx = schema.indexOf(col);
        select.append(`<option value="${idx}" data-type="${col.Type.toLowerCase()}">${col.Field}</option>`);
    });

    // Init input
    updateSearchInput();

    // Bind change
    select.off('change').on('change', updateSearchInput);

    // Bind search
    // We bind to the container because input is recreated
    $('#searchInputContainer').off('keyup change', 'input').on('keyup change', 'input', function () {
        const colIdx = select.val();
        const val = $(this).val();
        if (dataTableInstance) {
            dataTableInstance.column(colIdx).search(val).draw();
        }
    });

    $('#tableSearchToolbar').removeClass('d-none');
}

function updateSearchInput() {
    const selected = $('#searchColumnSelect option:selected');
    const type = selected.data('type') || 'text';
    const inputContainer = $('#searchInputContainer');
    inputContainer.empty();

    let inputType = 'text';
    if (type.includes('date') || type.includes('time')) inputType = 'date';
    else if (type.includes('int') || type.includes('decimal')) inputType = 'number';

    // If we simply empty/append, we lose focus if user is typing? No, updateSearchInput is only called on select change.

    const input = $(`<input type="${inputType}" class="form-control form-control-sm" id="searchInput" placeholder="Buscar...">`);
    inputContainer.append(input);
}

function resetTableSearch() {
    if (dataTableInstance) {
        dataTableInstance.search('').columns().search('').draw();
        // Reset global search input if exists (DataTable default)
        $('.dataTables_filter input').val('');

        $('#searchInput').val('');
    }
}
