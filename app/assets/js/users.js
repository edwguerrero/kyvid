/**
 * assets/js/users.js
 * Lógica para la gestión de usuarios y roles
 */

const UsersManager = {
    modal: null,

    init: function () {
        this.modal = new bootstrap.Modal(document.getElementById('userEditorModal'));
    },

    load: function () {
        // Cargar lista de usuarios
        $.getJSON('api/users.php?action=list', function (resp) {
            if (resp.success) {
                const tbody = $('#usersTable tbody');
                tbody.empty();

                resp.data.forEach(u => {
                    const badgeRole = u.role === 'admin'
                        ? '<span class="badge bg-danger">Admin</span>'
                        : '<span class="badge bg-primary">Viewer</span>';

                    const badgeStatus = u.is_active == 1
                        ? '<span class="badge bg-success">Activo</span>'
                        : '<span class="badge bg-secondary">Inactivo</span>';

                    // Formato bonito para el JSON de atributos
                    let attrs = '';
                    try {
                        const json = JSON.parse(u.attributes_json || '{}');
                        attrs = Object.keys(json).map(k =>
                            `<span class="badge bg-light text-dark border me-1">${k}: ${json[k]}</span>`
                        ).join('');
                    } catch (e) { attrs = '<span class="text-muted small">Sin datos</span>'; }

                    const tr = `
                        <tr>
                            <td class="fw-bold font-monospace">${u.code}</td>
                            <td>${u.name}</td>
                            <td>${badgeRole}</td>
                            <td>${attrs}</td>
                            <td>${badgeStatus}</td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary me-1" onclick='UsersManager.edit(${JSON.stringify(u)})'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="UsersManager.delete(${u.id})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                    tbody.append(tr);
                });
            } else {
                Swal.fire('Error', resp.error, 'error');
            }
        });
    },

    openEditor: function () {
        $('#userForm')[0].reset();
        $('#userId').val('');
        $('#userCode').prop('readonly', false);
        $('#passHelpText').text('Define una contraseña para el acceso.');
        $('#userAttributes').val('{}');
        this.modal.show();
    },

    edit: function (user) {
        $('#userForm')[0].reset();
        $('#userId').val(user.id);
        $('#userCode').val(user.code).prop('readonly', true); // No editar código para evitar conflictos
        $('#userRealName').val(user.name);
        $('#userPassword').val('');
        $('#passHelpText').text('Dejar vacío para conservar la contraseña actual.');
        $('#userRole').val(user.role);
        $('#userAttributes').val(user.attributes_json || '{}');
        $('#userIsActive').prop('checked', user.is_active == 1);

        this.modal.show();
    },

    save: function () {
        const id = $('#userId').val();
        const data = {
            id: id,
            code: $('#userCode').val(),
            name: $('#userRealName').val(),
            password: $('#userPassword').val(),
            role: $('#userRole').val(),
            attributes_json: $('#userAttributes').val(),
            is_active: $('#userIsActive').is(':checked') ? 1 : 0
        };

        // Validate JSON
        try {
            JSON.parse(data.attributes_json);
        } catch (e) {
            Swal.fire('Error JSON', 'El campo de atributos debe ser un JSON válido.', 'warning');
            return;
        }

        $.ajax({
            url: 'api/users.php?action=save',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function (resp) {
                if (resp.success) {
                    UsersManager.modal.hide();
                    UsersManager.load();
                    Swal.fire('Guardado', 'Usuario guardado correctamente', 'success');
                } else {
                    Swal.fire('Error', resp.error, 'error');
                }
            }
        });
    },

    delete: function (id) {
        Swal.fire({
            title: '¿Eliminar usuario?',
            text: "No podrás revertir esto.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'api/users.php?action=delete',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ id: id }),
                    success: function (resp) {
                        if (resp.success) {
                            UsersManager.load();
                            Swal.fire('Eliminado', 'El usuario ha sido eliminado.', 'success');
                        } else {
                            Swal.fire('Error', resp.error, 'error');
                        }
                    }
                });
            }
        });
    }
};

$(document).ready(function () {
    UsersManager.init();
});
