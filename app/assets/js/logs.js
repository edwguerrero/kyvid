/**
 * Action Logs Manager
 */
const LogsManager = {
    load: function () {
        const tbody = $('#logsTable tbody');
        tbody.html('<tr><td colspan="7" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando bitácora...</td></tr>');

        $.getJSON('api/index.php?action=logs_list', function (resp) {
            if (!resp.success) {
                tbody.html('<tr><td colspan="7" class="text-center text-danger">Error: ' + resp.error + '</td></tr>');
                return;
            }

            if (resp.data.length === 0) {
                tbody.html('<tr><td colspan="7" class="text-center py-4 text-muted">No hay registros de acciones aún.</td></tr>');
                return;
            }

            let html = '';
            resp.data.forEach(log => {
                const statusBadge = log.status === 'success'
                    ? '<span class="badge bg-success-subtle text-success border border-success-subtle">EXITO</span>'
                    : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">ERROR</span>';

                const triggerBadge = log.trigger_type === 'robot'
                    ? '<span class="text-warning fw-bold"><i class="bi bi-robot"></i> ROBOT</span>'
                    : '<span class="text-primary fw-bold"><i class="bi bi-person"></i> MANUAL</span>';

                html += `
                    <tr>
                        <td class="text-nowrap font-monospace small">${log.created_at}</td>
                        <td>${triggerBadge}</td>
                        <td>
                            <div class="fw-bold text-dark">${log.report_name || 'N/A'}</div>
                            <div class="text-muted small">${log.action_code || ''}</div>
                        </td>
                        <td>${log.user_name || '<span class="text-muted small">Sistema</span>'}</td>
                        <td class="text-center">${statusBadge}</td>
                        <td>
                            <div class="text-wrap" style="max-width: 400px; max-height: 80px; overflow-y: auto;">
                                ${log.message}
                            </div>
                        </td>
                        <td class="text-nowrap">${log.duration_ms} ms</td>
                    </tr>
                `;
            });
            tbody.html(html);
        });
    }
};
