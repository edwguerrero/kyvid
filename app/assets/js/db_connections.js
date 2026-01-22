/**
 * db_connections.js
 * Manages SQL Database Sources (db_connections table).
 */

const DbConnectionsManager = {
    API_URL: 'api/db_connections.php',
    _items: [],

    init: function () {
        if ($('#dbConnectionsList').length) {
            this.loadList();
        }
    },

    loadList: function () {
        $.getJSON(this.API_URL, { action: 'list' }, (resp) => {
            if (resp.success) {
                this._items = resp.data;
                this.renderList();
            }
        });
    },

    renderList: function () {
        const container = $('#dbConnectionsList');
        container.empty();

        if (this._items.length === 0) {
            container.html(`
                <tr>
                    <td colspan="5" class="text-center text-muted py-5">
                        <i class="bi bi-database-dash fs-1 d-block mb-3"></i>
                        <h5>Sin bases de datos externas</h5>
                        <p>Conecte MySQL o PostgreSQL externas para sus reportes.</p>
                    </td>
                </tr>
            `);
            return;
        }

        this._items.forEach((conn, index) => {
            const isActive = parseInt(conn.is_active) === 1;
            const statusBadge = isActive ?
                '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Activa</span>' :
                '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill">Inactiva</span>';

            const row = `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light text-primary rounded me-3 d-flex align-items-center justify-content-center" style="width:40px; height:40px;">
                                <i class="bi bi-database fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark">${conn.name}</div>
                                <span class="badge bg-light text-secondary border">${conn.type.toUpperCase()}</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="small">
                            <strong>Host:</strong> ${conn.host}:${conn.port}<br>
                            <strong>DB:</strong> ${conn.database_name}
                        </div>
                    </td>
                    <td>${statusBadge}</td>
                    <td><small class="text-muted">${conn.username}</small></td>
                    <td class="text-end">
                        <div class="btn-group shadow-sm">
                            <button class="btn btn-sm btn-white border" onclick="DbConnectionsManager.test(${conn.id})" title="Probar">
                                <i class="bi bi-activity text-warning"></i>
                            </button>
                            <button class="btn btn-sm btn-white border" onclick="DbConnectionsManager.openEditByIndex(${index})" title="Editar">
                                <i class="bi bi-pencil text-primary"></i>
                            </button>
                            <button class="btn btn-sm btn-white border" onclick="DbConnectionsManager.delete(${conn.id})" title="Eliminar">
                                <i class="bi bi-trash text-danger"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            container.append(row);
        });
    },

    openModal: function () {
        $('#dbConnId').val('');
        $('#dbConnForm')[0].reset();
        $('#dbConnModal').modal('show');
    },

    openEditByIndex: function (index) {
        const data = this._items[index];
        if (!data) return;

        $('#dbConnId').val(data.id);
        $('#dbConnName').val(data.name);
        $('#dbConnType').val(data.type);
        $('#dbConnHost').val(data.host);
        $('#dbConnPort').val(data.port);
        $('#dbConnDbName').val(data.database_name);
        $('#dbConnSchema').val(data.database_schema);
        $('#dbConnUser').val(data.username);
        $('#dbConnPass').val(''); // Clear pass for security
        $('#dbConnIsActive').prop('checked', data.is_active == 1);
        $('#dbConnModal').modal('show');
    },

    save: function () {
        const payload = {
            id: $('#dbConnId').val() || null,
            name: $('#dbConnName').val(),
            type: $('#dbConnType').val(),
            host: $('#dbConnHost').val(),
            port: $('#dbConnPort').val(),
            database_name: $('#dbConnDbName').val(),
            database_schema: $('#dbConnSchema').val(),
            username: $('#dbConnUser').val(),
            password: $('#dbConnPass').val(),
            is_active: $('#dbConnIsActive').is(':checked') ? 1 : 0
        };

        $.ajax({
            url: this.API_URL + '?action=save',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: (resp) => {
                if (resp.success) {
                    $('#dbConnModal').modal('hide');
                    this.loadList();
                    Swal.fire({ icon: 'success', title: 'Guardado', timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire('Error', resp.error, 'error');
                }
            }
        });
    },

    delete: function (id) {
        Swal.fire({
            title: '¿Eliminar Conexión SQL?',
            text: 'Esta acción desactivará la fuente de datos.',
            icon: 'warning',
            showCancelButton: true
        }).then(res => {
            if (res.isConfirmed) {
                $.ajax({
                    url: this.API_URL + '?action=delete',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ id: id }),
                    success: () => { this.loadList(); }
                });
            }
        });
    },

    test: function (id) {
        const payload = { id: id };
        if (!id) {
            payload.host = $('#dbConnHost').val();
            payload.port = $('#dbConnPort').val();
            payload.database_name = $('#dbConnDbName').val();
            payload.username = $('#dbConnUser').val();
            payload.password = $('#dbConnPass').val();
            payload.type = $('#dbConnType').val();
            payload.database_schema = $('#dbConnSchema').val();
        }

        Swal.fire({
            title: 'Probando SQL...',
            didOpen: () => { Swal.showLoading(); }
        });

        $.ajax({
            url: this.API_URL + '?action=test',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: (resp) => {
                Swal.close();
                if (resp.success) Swal.fire('¡Conectado!', resp.message, 'success');
                else Swal.fire('Error SQL', resp.error, 'error');
            },
            error: () => { Swal.close(); Swal.fire('Error', 'No se pudo contactar con el API', 'error'); }
        });
    }
};

window.DbConnectionsManager = DbConnectionsManager;
window.openDbConnectionModal = () => DbConnectionsManager.openModal();
