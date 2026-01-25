/**
 * Kyvid Flow - Visual SQL Builder
 * Allows users to build SQL queries visually by dragging tables and connecting columns.
 */

const VisualBuilder = {
    modalId: 'visualBuilderModal',
    canvasId: 'vbCanvas',
    sidebarId: 'vbSidebar',
    tables: [], // Schema
    addedTables: [], // On Canvas: { id, name, x, y, selectedColumns: [] }

    init: function () {
        // Inject Modal HTML if not exists
        if (!document.getElementById(this.modalId)) {
            this.injectModal();
        }
    },

    injectModal: function () {
        const modalHtml = `
        <div class="modal fade" id="${this.modalId}" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i>Diseñador Visual de Consultas</h5>
                        <div>
                            <button class="btn btn-warning btn-sm me-2" onclick="VisualBuilder.generateSQL()">
                                <i class="bi bi-code-square me-1"></i> Generar SQL
                            </button>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0 d-flex" style="height: 100vh; overflow: hidden;">
                        <!-- Sidebar -->
                        <div id="${this.sidebarId}" class="bg-light border-end" style="width: 250px; overflow-y: auto; padding: 10px;">
                            <h6 class="text-muted text-uppercase small fw-bold mb-3">Tablas Disponibles</h6>
                            <div class="mb-2">
                                <select id="vbConnectionFilter" class="form-select form-select-sm" onchange="VisualBuilder.filterTables()">
                                    <option value="">(Todas las conexiones)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <input type="text" id="vbSearchInput" class="form-control form-control-sm" placeholder="Buscar tabla..." onkeyup="VisualBuilder.filterTables()">
                            </div>
                            <div id="vbTableList" class="list-group">
                                <!-- Tables rendered here -->
                            </div>
                        </div>
                        
                        <!-- Canvas -->
                        <div id="${this.canvasId}" class="flex-grow-1 position-relative" style="background-color: #f8f9fa; background-image: radial-gradient(#dee2e6 1px, transparent 1px); background-size: 20px 20px; overflow: auto;">
                            <!-- Nodes rendered here -->
                            <div class="text-muted p-4 user-select-none" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); opacity: 0.5;">
                                <i class="bi bi-hand-index-thumb fs-1 d-block text-center"></i>
                                <span class="fs-5">Selecciona tablas del panel izquierdo</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    open: async function () {
        if (!document.getElementById(this.modalId)) this.init();

        // Reset state
        this.addedTables = [];
        document.getElementById(this.canvasId).innerHTML = '';

        const modal = new bootstrap.Modal(document.getElementById(this.modalId));
        modal.show();

        await this.loadSchema();

        // Check if we are editing an existing query
        const existingSql = $('#editSql').val();
        if (existingSql && existingSql.trim() !== '') {
            this.parseSQLToCanvas(existingSql);
        }
    },

    parseSQLToCanvas: function (sql) {
        if (!this.tables || this.tables.length === 0) {
            console.warn("Schema not loaded yet, skipping parse.");
            return;
        }

        const cleanSql = sql.replace(/\s+/g, ' ').trim();
        const tablesFound = [];

        // 1. Extract ALL Tables (FROM)
        const fromBlockMatch = sql.match(/FROM\s+([\s\S]+?)(?=\s+(WHERE|GROUP|ORDER|LIMIT)|\s*$)/i);

        if (fromBlockMatch) {
            const fromContent = fromBlockMatch[1];

            // Find Tables
            this.tables.forEach(tDef => {
                const tName = tDef.name;
                // Use original_name (real table name) if available, else name
                const tRealName = tDef.original_name || tDef.name;
                // Regex checks for "TableName"
                const regex = new RegExp(`\\b${tRealName}\\b`, 'gi');
                if (regex.test(fromContent)) {
                    // Check against list using UI name to map correctly back to definition
                    if (!tablesFound.includes(tName)) tablesFound.push(tName);
                }
            });
        }

        // 2. Add Tables to Canvas
        tablesFound.forEach(tName => {
            const tDef = this.tables.find(t => t.name === tName);
            if (tDef) {
                this.addTableToCanvas(tDef);

                // Tick Columns
                setTimeout(() => {
                    const node = this.addedTables.find(t => t.name === tName);
                    if (node) {
                        const tRealName = tDef.original_name || tDef.name;
                        tDef.columns.forEach(col => {
                            const colPattern = new RegExp(`\\b${tRealName}\\.${col.name}\\b`, 'i');
                            if (cleanSql.includes('*') || colPattern.test(cleanSql)) {
                                const chk = document.getElementById(`${node.id}_${col.name}_chk`);
                                if (chk) chk.checked = true;
                            }
                        });
                    }
                }, 100);
            }
        });
    },

    loadSchema: async function () {
        const list = document.getElementById('vbTableList');
        list.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-primary spinner-border-sm"></div></div>';

        try {
            const res = await fetch('api/index.php?action=get_schema');
            const data = await res.json();

            if (data.success) {
                this.tables = data.tables;

                // Populate Connection Filter
                const connNames = new Set();
                this.tables.forEach(t => {
                    // Extract [Connection] from "[Connection] TableName"
                    // If no bracket, it's "[Local]" (if we added it) or "Local" (if we didn't). 
                    // Our API adds [Local] now.
                    const match = t.name.match(/^\[(.*?)\]/);
                    if (match) {
                        connNames.add(match[0]); // "[Local]"
                    }
                });

                const filterSel = document.getElementById('vbConnectionFilter');
                filterSel.innerHTML = '<option value="">(Todas)</option>';
                Array.from(connNames).sort().forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.innerText = c;
                    filterSel.appendChild(opt);
                });

                this.renderSidebar();
            } else {
                list.innerHTML = `<div class="text-danger small p-2">${data.error}</div>`;
            }
        } catch (e) {
            console.error(e);
            list.innerHTML = `<div class="text-danger small p-2">Error de conexión</div>`;
        }
    },

    renderSidebar: function () {
        const list = document.getElementById('vbTableList');
        list.innerHTML = '';

        this.tables.forEach(t => {
            const item = document.createElement('button');
            item.className = 'list-group-item list-group-item-action py-2';
            item.innerHTML = `<i class="bi bi-table me-2 text-secondary"></i><span class="small fw-bold">${t.name}</span>`;
            item.onclick = () => this.addTableToCanvas(t);
            // Store raw name for filter
            item.setAttribute('data-name', t.name.toLowerCase());
            list.appendChild(item);
        });
    },

    filterTables: function () {
        const text = document.getElementById('vbSearchInput').value.toLowerCase();
        const conn = document.getElementById('vbConnectionFilter').value.toLowerCase();

        document.querySelectorAll('#vbTableList .list-group-item').forEach(el => {
            const name = el.getAttribute('data-name');
            const matchesText = name.includes(text);
            const matchesConn = conn === '' || name.startsWith(conn);

            el.style.display = (matchesText && matchesConn) ? 'block' : 'none';
        });
    },

    addTableToCanvas: function (tableDef) {
        if (this.addedTables.find(t => t.name === tableDef.name)) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'La tabla ya está en el lienzo', timer: 1500, showConfirmButton: false });
            return;
        }

        const id = 'vb_node_' + new Date().getTime();

        // Smart Grid Positioning
        const i = this.addedTables.length;
        const cols = 3;
        const cardWidth = 240;
        const cardHeight = 320;

        const col = i % cols;
        const row = Math.floor(i / cols);

        const node = {
            id: id,
            name: tableDef.name,
            def: tableDef,
            x: 50 + (col * cardWidth),
            y: 50 + (row * cardHeight)
        };
        this.addedTables.push(node);

        this.renderNode(node);
    },

    renderNode: function (node) {
        const el = document.createElement('div');
        el.id = node.id;
        el.className = 'card shadow-sm vb-node';
        el.style.position = 'absolute';
        el.style.left = node.x + 'px';
        el.style.top = node.y + 'px';
        el.style.width = '200px';
        el.style.zIndex = 10;

        let colsHtml = '';
        node.def.columns.forEach(col => {
            colsHtml += `
            <div class="d-flex align-items-center justify-content-between px-2 py-1 border-bottom border-light vb-col-row" style="font-size: 0.8rem;">
                <div class="form-check m-0">
                    <input class="form-check-input" type="checkbox" value="${col.name}" id="${node.id}_${col.name}_chk" onchange="VisualBuilder.toggleColumn('${node.id}', '${col.name}')">
                    <label class="form-check-label text-truncate" style="max-width: 150px;" for="${node.id}_${col.name}_chk" title="${col.name}">${col.name}</label>
                </div>
            </div>`;
        });

        el.innerHTML = `
            <div class="card-header bg-white py-2 px-2 d-flex justify-content-between align-items-center handle" style="cursor: grab;">
                <span class="small fw-bold text-primary text-truncate" style="max-width: 150px;" title="${node.name}">${node.name}</span>
                <i class="bi bi-x text-danger" style="cursor: pointer;" onclick="VisualBuilder.removeNode('${node.id}')"></i>
            </div>
            <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                ${colsHtml}
            </div>
        `;

        document.getElementById(this.canvasId).appendChild(el);
        this.makeDraggable(el);
    },

    makeDraggable: function (el) {
        let isDragging = false;
        let startX, startY, initialLeft, initialTop;

        const handle = el.querySelector('.handle');

        handle.addEventListener('mousedown', (e) => {
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            initialLeft = el.offsetLeft;
            initialTop = el.offsetTop;
            el.style.zIndex = 100;
            document.body.style.cursor = 'grabbing';
            handle.style.cursor = 'grabbing';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            el.style.left = (initialLeft + dx) + 'px';
            el.style.top = (initialTop + dy) + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                el.style.zIndex = 10;
                document.body.style.cursor = 'default';
                handle.style.cursor = 'grab';
            }
        });
    },

    removeNode: function (nodeId) {
        const el = document.getElementById(nodeId);
        if (el) el.remove();
        this.addedTables = this.addedTables.filter(t => t.id !== nodeId);
    },

    toggleColumn: function (nodeId, colName) {
        // Mark column as selected 
    },

    generateSQL: function () {
        if (this.addedTables.length === 0) {
            Swal.fire({ icon: 'warning', title: 'Agrega tablas primero' });
            return;
        }

        let sql = "SELECT\n";
        let columns = [];

        // 1. Gather Selected Columns
        this.addedTables.forEach(t => {
            const tableEl = document.getElementById(t.id);
            const checkboxes = tableEl.querySelectorAll('input[type="checkbox"]:checked');
            // Use table real name if available
            const tRealName = t.def.original_name || t.name;

            checkboxes.forEach(chk => {
                columns.push(`    ${tRealName}.${chk.value}`);
            });
        });

        if (columns.length === 0) columns.push("    *");
        sql += columns.join(",\n");
        sql += "\nFROM\n";

        // Simple Comma Separated Tables
        const tableNames = this.addedTables.map(t => "    " + (t.def.original_name || t.name));
        sql += tableNames.join(",\n");

        // Output to User
        Swal.fire({
            title: 'Consulta Generada',
            html: `<textarea class="form-control font-monospace" rows="10" id="generatedSqlArea">${sql}</textarea>`,
            showCancelButton: true,
            confirmButtonText: 'Usar Consulta',
            cancelButtonText: 'Cancelar',
        }).then((result) => {
            if (result.isConfirmed) {
                const editorSql = document.getElementById('editSql');
                if (editorSql) {
                    $('#editSql').val(sql);
                    const modal = bootstrap.Modal.getInstance(document.getElementById(VisualBuilder.modalId));
                    modal.hide();
                } else {
                    const modal = bootstrap.Modal.getInstance(document.getElementById(VisualBuilder.modalId));
                    modal.hide();
                    createNewReportWithSQL(sql);
                }
            }
        });
    }
};
