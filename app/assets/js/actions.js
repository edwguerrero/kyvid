// assets/js/actions.js

let actionsTable = null;
let phpEditor = null;
let pendingPhpContent = null; // Guardar contenido mientras el modal termina de abrirse
let actionsSchemaCache = {}; // Cache para esquemas de parámetros

$(document).ready(function () {
    initActions();
});

function initActions() {
    // Inicializar CodeMirror cuando el modal se muestra
    $('#actionModal').on('shown.bs.modal', function () {
        if (!phpEditor) {
            phpEditor = CodeMirror(document.getElementById("actionPhpEditor"), {
                mode: "application/x-httpd-php-open",
                theme: "dracula",
                lineNumbers: true,
                matchBrackets: true,
                indentUnit: 4,
                tabSize: 4,
                viewportMargin: Infinity
            });
        }

        // Cargar contenido pendiente si existe
        if (pendingPhpContent !== null) {
            phpEditor.setValue(pendingPhpContent);
            pendingPhpContent = null;
        }

        phpEditor.refresh();
        phpEditor.focus();
    });
}

function loadActions() {
    $.get('api/actions.php?action=list', function (resp) {
        if (resp.success) {
            const list = $('#actionsListBody');
            list.empty();
            resp.data.forEach(a => {
                const statusBadge = a.is_active == 1
                    ? '<span class="badge bg-success">Activo</span>'
                    : '<span class="badge bg-danger">Inactivo</span>';

                list.append(`
                    <tr>
                        <td><code class="fw-bold">${a.code}</code></td>
                        <td>${a.name}</td>
                        <td><span class="badge bg-light text-dark border">${a.category}</span></td>
                        <td>${statusBadge}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="editAction(${a.id})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteAction(${a.id})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        }
    });
}

function openActionModal() {
    $('#actionId').val('');
    $('#actionForm')[0].reset();
    $('#actionModalLabel').text('Nueva Función // FaaS');

    const defaultTpl = "// Escribe tu lógica PHP aquí\n// Tienes acceso a $results (los datos) y $params (los parámetros)";

    if (phpEditor) {
        phpEditor.setValue(defaultTpl);
    } else {
        pendingPhpContent = defaultTpl;
    }

    $('#actionModal').modal('show');
}

function editAction(id) {
    $.get('api/actions.php?action=get&id=' + id, function (resp) {
        if (resp.success && resp.data) {
            const a = resp.data;
            $('#actionId').val(a.id);
            $('#actionCode').val(a.code);
            $('#actionName').val(a.name);
            $('#actionCategory').val(a.category);
            $('#actionDesc').val(a.description);
            $('#actionSchema').val(a.parameters_schema);
            $('#actionIsActive').prop('checked', a.is_active == 1);

            $('#actionModalLabel').text('Editar Función: ' + a.code);

            // Asignar contenido para que el evento shown.bs.modal lo use
            if (phpEditor) {
                phpEditor.setValue(a.php_content || "");
            } else {
                pendingPhpContent = a.php_content || "";
            }

            $('#actionModal').modal('show');
        }
    });
}

function saveAction() {
    const data = {
        id: $('#actionId').val(),
        code: $('#actionCode').val(),
        name: $('#actionName').val(),
        category: $('#actionCategory').val(),
        description: $('#actionDesc').val(),
        parameters_schema: $('#actionSchema').val(),
        php_content: phpEditor ? phpEditor.getValue() : '',
        is_active: $('#actionIsActive').is(':checked') ? 1 : 0
    };

    if (!data.code || !data.name) {
        Swal.fire('Error', 'Código y Nombre son obligatorios', 'error');
        return;
    }

    $.ajax({
        url: 'api/actions.php?action=save',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(data),
        success: function (resp) {
            if (resp.success) {
                Swal.fire('Guardado', 'La función se guardó correctamente', 'success');
                $('#actionModal').modal('hide');
                loadActions();
            } else {
                Swal.fire('Error', resp.error, 'error');
            }
        }
    });
}

function deleteAction(id) {
    Swal.fire({
        title: '¿Eliminar función?',
        text: "Esta operación no se puede deshacer.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Sí, eliminar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.get('api/actions.php?action=delete&id=' + id, function (resp) {
                if (resp.success) {
                    loadActions();
                    Swal.fire('Eliminado', 'La función ha sido eliminada', 'success');
                }
            });
        }
    });
}

function testAction() {
    const code = $('#actionCode').val();
    if (!code) {
        Swal.fire('Error', 'Debes asignar un código a la función primero.', 'warning');
        return;
    }

    // Datos de prueba genéricos
    const mockData = [
        { id: 1, name: "Producto A", price: 100, code: "P001" },
        { id: 2, name: "Producto B", price: 200, code: "P002" }
    ];

    // Intentar parsear params si hay algo escrito
    let mockParams = {};
    try {
        const schema = JSON.parse($('#actionSchema').val() || '{}');
        // Crear un objeto de prueba basado en el schema
        Object.keys(schema).forEach(k => {
            mockParams[k] = schema[k].default || "TEST_VALUE";
        });
    } catch (e) { }

    Swal.fire({
        title: 'Prueba Seca (Dry Run)',
        text: 'Se ejecutará la función con datos de prueba genéricos.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Ejecutar'
    }).then((r) => {
        if (r.isConfirmed) {
            $.ajax({
                url: 'api/actions.php?action=test',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    code: code,
                    test_data: mockData,
                    test_params: mockParams
                }),
                success: function (resp) {
                    if (resp.success) {
                        Swal.fire({
                            title: 'Resultado Exitoso',
                            html: `<pre class="text-start bg-light p-2 small" style="max-height: 300px; overflow: auto;">${JSON.stringify(resp.output, null, 2)}</pre>`,
                            icon: 'success'
                        });
                    } else {
                        Swal.fire('Error en ejecución', resp.error, 'error');
                    }
                }
            });
        }
    });
}

/**
 * Llena el selector de funciones en el editor de reportes
 */
function populateActionsForEditor(selectedCode = null, selectedParams = null) {
    $.get('api/actions.php?action=list', function (resp) {
        if (resp.success) {
            const select = $('#editPostActionCode');
            select.find('option:not(:first)').remove();

            actionsSchemaCache = {}; // Limpiar cache anterior

            resp.data.forEach(a => {
                // Almacenar el esquema si existe
                try {
                    actionsSchemaCache[a.code] = a.parameters_schema ? JSON.parse(a.parameters_schema) : {};
                } catch (e) { actionsSchemaCache[a.code] = {}; }

                const selected = (a.code === selectedCode) ? 'selected' : '';
                select.append(`<option value="${a.code}" ${selected}>${a.name} [${a.code}]</option>`);
            });

            $('#editPostActionParams').val(selectedParams || '');
        }
    });
}

// Escuchar cambios en el selector para dar feedback inmediato
$(document).on('change', '#editPostActionCode', function () {
    const val = $(this).val();
    if (val) {
        let msg = "Función seleccionada.";
        if (val === 'UTIL_EMAIL_REPORT') {
            msg = "✉️ Envío por Correo activado.";
        }

        // Generar ejemplo de JSON basado en el esquema
        const schema = actionsSchemaCache[val] || {};
        const example = {};
        Object.keys(schema).forEach(key => {
            const field = schema[key];
            example[key] = field.default !== undefined ? field.default : (field.type === 'number' ? 0 : "...");
        });

        const jsonExample = JSON.stringify(example, null, 4);
        const currentVal = $('#editPostActionParams').val().trim();

        const applyExample = () => {
            $('#editPostActionParams').val(jsonExample);
            if (typeof Toast !== 'undefined') {
                Toast.fire({ icon: 'success', title: msg + " Ejemplo cargado." });
            }
        };

        // Si ya hay algo que no es un objeto vacío o [] vacío, preguntar antes de sobrescribir
        if (currentVal && currentVal !== "{}" && currentVal !== "[]" && currentVal !== "") {
            Swal.fire({
                title: '¿Cargar ejemplo?',
                text: "Ya existen parámetros configurados. ¿Deseas reemplazarlos con el ejemplo de esta función?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, Reemplazar',
                cancelButtonText: 'Mantener Actual'
            }).then((res) => {
                if (res.isConfirmed) applyExample();
            });
        } else {
            applyExample();
        }
    }
});
