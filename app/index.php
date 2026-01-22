<?php
session_start();
// --- AUTHENTICATION CHECK ---
if (!isset($_SESSION['user_id'])) {
    // Render LOGIN PAGE only
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Kyvid Flow | Iniciar Sesión</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            :root { --primary-color: #0d6efd; --bg-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); }
            body { 
                background: var(--bg-gradient); 
                height: 100vh; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                font-family: 'Segoe UI', sans-serif;
            }
            .login-card {
                background: white;
                padding: 2.5rem;
                border-radius: 1rem;
                box-shadow: 0 10px 25px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 400px;
                text-align: center;
                transition: transform 0.3s;
            }
            .brand-icon { font-size: 3rem; color: var(--primary-color); margin-bottom: 1rem; }
            .step-container { display: none; }
            .step-container.active { display: block; animation: fadeIn 0.5s; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="mb-4">
                <i class="bi bi-diagram-3-fill brand-icon"></i>
                <h3 class="fw-bold text-dark">Kyvid Flow</h3>
                <p class="text-muted small"><Verdadera Inteligencia de Negocios</p>
            </div>

            <!-- STEP 1: USER CODE -->
            <div id="step1" class="step-container active">
                <h5 class="mb-3">Bienvenido</h5>
                <form id="codeForm">
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="userCode" placeholder="Código" required autofocus>
                        <label for="userCode">Código de Usuario</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 btn-lg shadow-sm">
                        Siguiente <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </form>
            </div>

            <!-- STEP 2: PASSWORD -->
            <div id="step2" class="step-container">
                <div class="mb-4">
                    <span class="badge bg-light text-primary border rounded-pill px-3 py-2 mb-2">
                        <i class="bi bi-person-circle me-1"></i> <span id="userNameDisplay">Usuario</span>
                    </span>
                </div>
                <form id="passwordForm">
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="userPass" placeholder="Contraseña" required>
                        <label for="userPass">Contraseña</label>
                    </div>
                    <div class="form-check text-start mb-3">
                        <input class="form-check-input" type="checkbox" id="rememberMe">
                        <label class="form-check-label small text-muted" for="rememberMe">
                            Mantener sesión iniciada
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 btn-lg shadow-sm">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Ingresar
                    </button>
                    <button type="button" class="btn btn-link btn-sm text-muted mt-3 text-decoration-none" onclick="resetLogin()">
                        <i class="bi bi-arrow-left me-1"></i> Cambiar usuario
                    </button>
                </form>
            </div>
            
            <div class="mt-4 pt-3 border-top">
                <small class="text-muted" style="font-size: 0.75rem;">Kyvid Flow &copy; 2026</small>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
            const API_URL = 'api/auth.php';
            let currentCode = '';

            document.getElementById('codeForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const code = document.getElementById('userCode').value.trim();
                if(!code) return;

                // Special handling for legacy admin bypass if DB migration failed
                if(code === 'admin') {
                     // Proceed to password immediately
                     showStep2('Administrador (Legacy/System)');
                     currentCode = code;
                     return;
                }

                try {
                    const res = await fetch(API_URL + '?action=check_code', {
                        method: 'POST', body: JSON.stringify({ code })
                    });
                    const data = await res.json();
                    
                    if(data.success) {
                        currentCode = data.corrected_code || code;
                        showStep2(data.name);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Acceso Denegado', text: data.error || 'Verifica tu código de acceso.', timer: 3000, showConfirmButton: false });
                    }
                } catch(err) { console.error(err); }
            });

            document.getElementById('passwordForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const password = document.getElementById('userPass').value;
                
                try {
                    // Try New Login System first
                    let action = 'login_account';
                    // If it was the legacy admin quick-pass (Step 1 bypass), we might need fallback logic
                    // But our backend 'login_account' handles 'admin' code too if seeded. 
                    // Fallback to old 'login' action only if specific error implies table missing.
                    
                    const res = await fetch(API_URL + '?action=' + action, {
                        method: 'POST', 
                        body: JSON.stringify({ code: currentCode, password })
                    });
                    const data = await res.json();

                    if(data.success) {
                        window.location.reload();
                    } else {
                        // Fallback Trial: Old Auth endpoint for legacy admin
                        if (currentCode === 'admin') {
                             const res2 = await fetch(API_URL + '?action=login', {method:'POST', body: JSON.stringify({password})});
                             const data2 = await res2.json();
                             if(data2.success) window.location.reload();
                             else Swal.fire({ icon: 'error', title: 'Acceso Denegado', text: 'Contraseña incorrecta.' });
                        } else {
                             Swal.fire({ icon: 'error', title: 'Acceso Denegado', text: 'Contraseña incorrecta.' });
                        }
                    }
                } catch(err) { console.error(err); }
            });

            function showStep2(name) {
                document.getElementById('userNameDisplay').textContent = name;
                document.getElementById('step1').classList.remove('active');
                document.getElementById('step2').classList.add('active');
                setTimeout(() => document.getElementById('userPass').focus(), 100);
            }

            function resetLogin() {
                document.getElementById('step2').classList.remove('active');
                document.getElementById('step1').classList.add('active');
                document.getElementById('userCode').value = '';
                document.getElementById('userPass').value = '';
                document.getElementById('userCode').focus();
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyvid Flow | Donde tus datos toman acción</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/rowgroup/1.3.1/css/rowGroup.bootstrap5.min.css" rel="stylesheet">
    <!-- jQuery UI & PivotTable CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/themes/base/jquery-ui.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.css">
    <!-- Gridstack.js -->
    <link href="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack-extra.min.css" rel="stylesheet"/>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            /* --- CONFIGURACIÓN CENTRAL DE COLORES (Azul Kyvid Moderno) --- */
            /* Se modifican estos valores para unificar con la Landing Page */
            --primary-color: #0d6efd;       /* Azul Kyvid (Vibrante) */
            --primary-hover: #0b5ed7;       /* Azul Oscuro (Hover) */
            --secondary-color: #5a5c69;     /* Gris para textos secundarios */
            --header-bg-start: #343a40;     /* Gris Oscuro (Header fondo) */
            --header-bg-end: #212529;       /* Negro Suave (Header fondo) */
            --light-bg: #f8f9fc;            /* Fondo General de la App */
            --accent-yellow: #ffc107;       /* Acentos (Robot, Advertencias) */
        }
        
        /* Tema Verde (Legacy) */
        [data-theme="green"] {
            --primary-color: #198754;
            --primary-hover: #146c43;
        }

        /* Tema Oscuro (Cyberpunk/Dark Mode) */
        [data-theme="dark"] {
            --primary-color: #0d6efd;
            --primary-hover: #0b5ed7;
            --light-bg: #1a1d21;
            --secondary-color: #adb5bd;
            --header-bg-start: #0f1114;
            --header-bg-end: #000000;
        }
        [data-theme="dark"] .card, [data-theme="dark"] .widget-header, [data-theme="dark"] .list-group-item, [data-theme="dark"] .accordion-body {
            background-color: #212529 !important;
            color: #e9ecef;
            border-color: #343a40;
        }
        [data-theme="dark"] .card-header {
            background-color: #2c3035 !important;
            color: #fff;
            border-bottom-color: #343a40;
        }
        [data-theme="dark"] .table {
            color: #e9ecef !important;
            border-color: #343a40;
            --bs-table-bg: transparent; 
            --bs-table-striped-bg: rgba(255, 255, 255, 0.05);
            --bs-table-hover-bg: rgba(255, 255, 255, 0.075);
            --bs-table-color: #e9ecef;
        }
        [data-theme="dark"] .table td, [data-theme="dark"] .table th {
            color: #e9ecef !important;
            background-color: transparent !important; /* Importante para que no pise el BG */
        }
        [data-theme="dark"] .table-light {
             background-color: #343a40;
             color: #fff;
             --bs-table-bg: #343a40;
             --bs-table-striped-bg: #343a40;
        }
        [data-theme="dark"] .scenario-canvas {
            background-color: #2c3035;
        }
        [data-theme="dark"] .grid-stack-item-content {
            background-color: #212529;
            color: #fff;
        }
        
        /* IMPRESIÓN: Siempre Blanco para ahorrar tinta y asegurar legibilidad */
        @media print {
            body, .card, .grid-stack-item-content, .table {
                background-color: #fff !important;
                color: #000 !important;
            }
            .main-header, .no-print { display: none !important; }
        }
        body { 
            background-color: var(--light-bg); 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-header {
            background: linear-gradient(135deg, var(--header-bg-start) 0%, var(--header-bg-end) 100%);
            color: white;
            padding-top: 1rem;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
            border-bottom: 4px solid var(--primary-color); /* Toque verde en el header */
        }
        .card { 
            border: none;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e3e6f0;
            padding-top: 1rem;
            padding-bottom: 1rem;
            font-weight: bold;
            color: var(--secondary-color); /* Antes primary, ahora más sobrio */
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--primary-color);
        }
        .filter-toggle {
            cursor: pointer;
            transition: all 0.3s;
        }
        .filter-toggle:hover {
            opacity: 0.7;
        }
        .filter-toggle .bi-chevron-down {
            transition: transform 0.3s;
        }
        .collapsed .bi-chevron-down {
            transform: rotate(-180deg);
        }
        /* OVERRIDES for Corporate Theme */
        .text-primary { color: var(--primary-color) !important; }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
            background-color: var(--primary-hover) !important;
            border-color: var(--primary-hover) !important;
        }

        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        .btn-outline-primary:disabled {
             color: var(--primary-color);
             border-color: var(--primary-color);
        }
        .form-label {
            font-weight: 600;
            font-size: 0.9rem;
            color: #5a5c69;
        }
        #chartContainer {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .editable-cell {
            cursor: cell;
            transition: background-color 0.2s;
        }
        .editable-cell:hover {
            background-color: #fff9db !important;
            outline: 1px dashed #fab005;
        }
        .editable-cell:focus {
            background-color: #fff !important;
            outline: 2px solid #4e73df;
            box-shadow: 0 0 5px rgba(78, 115, 223, 0.3);
        }
        @keyframes robot-glow {
            from { opacity: 0.6; transform: scale(1); }
            to { opacity: 1; transform: scale(1.2); }
        }
        .anim-robot {
            display: inline-block;
            animation: robot-glow 1s ease-in-out infinite alternate;
            color: var(--accent-yellow);
        }
        .nav-tabs .nav-link {
            color: var(--secondary-color);
            font-weight: bold;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
        }
        .scenario-canvas {
            min-height: 600px;
            background-color: #f1f3f9;
            border-radius: 8px;
            padding: 10px;
        }
        .grid-stack-item-content {
            background: white;
            border-radius: 8px;
            box-shadow: 0 0.15rem 1rem 0 rgba(58, 59, 69, 0.1);
            padding: 10px;
            overflow: hidden !important;
        }
        .widget-header {
            border-bottom: 1px solid #eee;
            margin-bottom: 10px;
            padding-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .widget-body {
            height: calc(100% - 40px);
            overflow: auto;
        }
        .ai-narrative-box {
            background: #fff;
            border: 1px solid #e3e6f0;
            border-left: 6px solid var(--primary-color);
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            color: #4a4a4a;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        }
        .bg-primary-light {
            background-color: rgba(78, 115, 223, 0.1) !important;
        }
        
        /* Admin Security Mode */
        .admin-only { display: none !important; }
        body.is-admin .admin-only { display: block !important; }
        body.is-admin .nav-item.admin-only { display: list-item !important; }
        body.is-admin .btn.admin-only { display: inline-block !important; }
        @media (max-width: 768px) {
            .main-header h3 { font-size: 1.25rem; }
            .main-header small { display: none; }
            .main-header .container { flex-direction: column; text-align: center; }
            .main-header .d-flex.align-items-center { margin-top: 1rem; width: 100%; justify-content: center; }
            
            .nav-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
            }
            .nav-tabs .nav-item { white-space: nowrap; }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start !important;
            }
            .card-tools {
                margin-top: 10px;
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                width: 100%;
            }
            .card-tools .btn {
                flex-grow: 1;
                font-size: 0.75rem;
                padding: 0.25rem 0.5rem;
            }
            
            #chartContainer { height: 250px; }
            
            .scenario-canvas { padding: 5px; min-height: auto; height: auto !important; }
            .grid-stack-item { 
                position: relative !important; 
                width: 100% !important; 
                left: 0 !important; 
                top: 0 !important;
                height: auto !important;
                margin-bottom: 15px !important;
            }
            .grid-stack-item-content { 
                position: relative !important; 
                inset: 0 !important;
                padding: 12px; 
                min-height: 200px; 
            }
            .widget-body { height: auto; max-height: none; }
            .grid-stack { height: auto !important; }
            
            /* Ajustes para filtros del escenario en móvil */
            /* Ajustes para el editor de tablas en móvil */
            #tablesView .col-md-3 {
                margin-bottom: 20px;
            }
            #tablesList {
                max-height: 200px;
                overflow-y: auto;
            }
            #tableHeaderPanel {
                flex-direction: column;
                align-items: flex-start !important;
            }
            #tableActions {
                margin-top: 10px;
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
            }
            #tableActions .btn {
                flex-grow: 1;
            }
            
            /* Ajustar botones de password change en móvil */
            #passwordChangeModal .modal-footer {
                flex-direction: column-reverse;
            }
            #passwordChangeModal .modal-footer .btn {
                width: 100%;
                margin: 5px 0;
            }
            /* Ajustes para DataTables en móvil */
            .dt-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                width: 100%;
                margin-bottom: 15px;
            }
            .dt-buttons .btn {
                flex-grow: 1;
            }
            .dataTables_filter {
                width: 100%;
                text-align: left !important;
            }
            .dataTables_filter input {
                width: 100% !important;
                margin-left: 0 !important;
            }
            .dataTables_info, .dataTables_paginate {
                text-align: center !important;
                width: 100%;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="container d-flex align-items-center justify-content-between">
        <h3 class="mb-0"><i class="bi bi-diagram-3-fill me-2"></i>Kyvid Flow <small class="text-white-50 fs-6 ms-2">| Datos en Acción</small></h3>
        <div class="d-flex align-items-center">
            <span class="text-white me-3 small d-none d-md-inline">
                <i class="bi bi-person-circle text-white-50 me-1"></i> <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Usuario'); ?>
                <span class="badge bg-primary ms-1"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'viewer'); ?></span>
            </span>
            <div class="form-check form-switch me-4 mb-0 admin-only">
                <input class="form-check-input" type="checkbox" id="masterRobotSwitch" onchange="toggleRobot(this.checked)">
                <label class="form-check-label text-white fw-bold" for="masterRobotSwitch" id="robotStatusLabel">
                    <i class="bi bi-robot me-1"></i> Robot
                </label>
            </div>
            <button class="btn btn-sm btn-light border-0 me-2" id="themeToggleBtn" onclick="toggleTheme()" title="Cambiar Tema">
                <i class="bi bi-palette-fill text-primary"></i>
            </button>
            <button class="btn btn-sm btn-light border-0" onclick="logout()" title="Cerrar Sesión">
                <i class="bi bi-box-arrow-right text-danger"></i>
            </button>
        </div>
    </div>
</header>

<div class="container mb-4">
    <ul class="nav nav-tabs border-0" id="mainTabs">
        <li class="nav-item">
            <a class="nav-link active" href="#" onclick="switchMainTab('reports')"><i class="bi bi-file-earmark-bar-graph"></i> Reportes</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" onclick="switchMainTab('scenarios')"><i class="bi bi-layers"></i> Escenarios</a>
        </li>
        <li class="nav-item admin-only">
            <a class="nav-link" href="#" onclick="switchMainTab('users')"><i class="bi bi-people-fill"></i> Usuarios</a>
        </li>
        <li class="nav-item admin-only">
            <a class="nav-link" href="#" onclick="switchMainTab('tables')"><i class="bi bi-table"></i> Tablas de Datos</a>
        </li>
        <li class="nav-item admin-only">
            <a class="nav-link" href="#" onclick="switchMainTab('db_connections')"><i class="bi bi-database"></i> Bases de Datos</a>
        </li>
        <li class="nav-item admin-only">
            <a class="nav-link" href="#" onclick="switchMainTab('services')"><i class="bi bi-cpu-fill"></i> Servicios</a>
        </li>
        <li class="nav-item admin-only">
            <a class="nav-link" href="#" onclick="switchMainTab('actions')"><i class="bi bi-play-circle-fill"></i> Funciones</a>
        </li>
        <li class="nav-item admin-only">
            <a class="nav-link" href="#" onclick="switchMainTab('logs')"><i class="bi bi-journal-text"></i> Bitácora</a>
        </li>
    </ul>
</div>

<div class="container" id="reportsView">
    
    <!-- Selección de Reporte -->
    <div class="card">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <label class="form-label" for="reportSelect">Seleccione el Reporte a generar:</label>
                    <select id="reportSelect" class="form-select form-select-lg">
                        <option value="">Cargando catálogo de reportes...</option>
                    </select>
                </div>
                <div class="col-md-6 text-end">
                     <button class="btn btn-primary admin-only shadow-sm" id="btnNewReport">
                        <i class="bi bi-plus-lg me-1"></i> Nuevo Reporte
                     </button>
                     <button class="btn btn-outline-primary admin-only" id="btnEditReport" disabled>
                        <i class="bi bi-pencil me-1"></i> Editar
                     </button>
                     <button class="btn btn-outline-danger admin-only" id="btnDeleteReport" disabled>
                        <i class="bi bi-trash me-1"></i> Eliminar
                     </button>
                </div>
                <div class="col-12">
                     <small id="reportDescription" class="text-muted d-block mt-2 fst-italic">
                         <i class="bi bi-info-circle me-1"></i> Seleccione una opción para ver detalles.
                     </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Área de Filtros Dinámicos -->
    <div id="filterCard" class="card d-none">
        <div class="card-header filter-toggle user-select-none" data-bs-toggle="collapse" data-bs-target="#filterBody" aria-expanded="true" aria-controls="filterBody">
            <span><i class="bi bi-funnel me-2"></i>Filtros de Búsqueda</span>
            <i class="bi bi-chevron-down"></i>
        </div>
        <div id="filterBody" class="collapse show">
            <div class="card-body">
                <form id="filterForm">
                    <div id="dynamicFilters" class="row g-3">
                        <!-- Los filtros se inyectarán aquí -->
                    </div>
                    <div class="mt-4 pt-3 border-top text-end">
                        <button type="button" class="btn btn-secondary me-2" id="btnResetFilters">
                            <i class="bi bi-eraser me-1"></i> Limpiar
                        </button>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-search me-1"></i> Generar Reporte
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Resultados y Gráficos (Vista Reporte) -->
    <div id="viewReportContainer">
        <!-- Gráficos (Colapsable) -->
        <div class="card d-none" id="chartCard">
            <div class="card-header filter-toggle user-select-none collapsed" data-bs-toggle="collapse" data-bs-target="#chartBody" aria-expanded="false" aria-controls="chartBody">
                <span><i class="bi bi-graph-up me-2"></i>Resumen Gráfico</span>
                <i class="bi bi-chevron-down"></i>
            </div>
            <div id="chartBody" class="collapse">
                <div class="card-body">
                    <div id="chartContainer">
                        <canvas id="reportChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Resultados -->
        <div class="card d-none" id="resultsCard">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-table me-2"></i>Resultados del Reporte
                    <span id="reportActionBadge" class="badge bg-info d-none ms-2"></span>
                </span>
                <div class="card-tools">
                   <button class="btn btn-sm btn-primary d-none shadow-sm" id="btnProcessReport" onclick="processReport()">
                        <i class="bi bi-play-circle me-1"></i> Procesar
                   </button>
                   <button class="btn btn-sm btn-outline-primary me-2" onclick="switchToPivot()">
                        <i class="bi bi-grid-3x3 me-1"></i> Analizar en Dinámica
                   </button>
                    <button class="btn btn-sm btn-outline-primary me-2" onclick="shareReport()">
                        <i class="bi bi-share me-1"></i> Compartir
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="executeReport()">
                        <i class="bi bi-arrow-clockwise"></i> Actualizar
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="resultsTable" class="table table-hover table-bordered w-100">
                        <thead class="table-light">
                            <tr id="tableHeaderRow"></tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot id="tableFooter"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Vista Tabla Dinámica -->
    <div id="viewPivotContainer" class="d-none">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center bg-info text-white">
                <span><i class="bi bi-grid-3x3 me-2"></i>Análisis Dinámico (Pivot Table)</span>
                <div>
                    <button class="btn btn-sm btn-success me-2 d-none" id="btnProcessReportPivot" onclick="processReport()">
                        <i class="bi bi-play-circle me-1"></i> Procesar
                    </button>
                    <button class="btn btn-sm btn-warning me-2" id="btnPivotGraph" onclick="togglePivotChart()">
                        <i class="bi bi-bar-chart-fill me-1"></i> Graficar
                    </button>
                    <button class="btn btn-sm btn-light text-info" onclick="switchToReport()">
                         <i class="bi bi-arrow-left me-1"></i> Volver al Reporte
                    </button>
                </div>
            </div>
            <div class="card-body" style="overflow-x: auto;">
                 <div id="pivotOutput" class="w-100"></div>
            </div>
        </div>
    </div>
</div>

<!-- Vista Escenarios (Escenarios de Negocio) -->
<div class="container d-none" id="scenariosView">
    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center flex-grow-1 me-3" style="max-width: 400px;">
                <label class="form-label mb-0 me-3 text-nowrap">Escenario:</label>
                <select id="scenarioSelect" class="form-select">
                    <option value="">Seleccione un escenario...</option>
                </select>
            </div>
            <div>
                <button class="btn btn-primary btn-sm admin-only shadow-sm" onclick="openNewScenario()">
                    <i class="bi bi-plus-lg"></i> Nuevo Escenario
                </button>
                <button class="btn btn-outline-primary btn-sm mx-1 admin-only" id="btnEditScenario" disabled onclick="editCurrentScenario()">
                    <i class="bi bi-pencil"></i> Editar
                </button>
                <button class="btn btn-outline-danger btn-sm admin-only" id="btnDeleteScenario" disabled onclick="deleteCurrentScenario()">
                    <i class="bi bi-trash"></i> Eliminar
                </button>
            </div>
        </div>
        <!-- Master Filters Accordion -->
        <div class="accordion accordion-flush disabled" id="scenarioFiltersAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header" id="flush-headingOne">
                    <button class="accordion-button collapsed py-2 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#flush-collapseOne" aria-expanded="false" aria-controls="flush-collapseOne">
                        <i class="bi bi-funnel me-2"></i> Filtros del Escenario (Aplica a todos los reportes)
                    </button>
                </h2>
                <div id="flush-collapseOne" class="accordion-collapse collapse" aria-labelledby="flush-headingOne" data-bs-parent="#scenarioFiltersAccordion">
                    <div class="accordion-body bg-light">
                        <form id="scenarioFilterForm" class="row g-2 align-items-end">
                            <div class="col-12 text-center text-muted fst-italic py-2" id="noFiltersMsg">
                                No hay filtros comunes disponibles para este escenario.
                            </div>
                            <!-- Filters will be injected here -->
                        </form>
                        <div class="text-end mt-2 d-none" id="scenarioFilterActions">
                            <button class="btn btn-sm btn-secondary" type="button" onclick="resetScenarioFilters()">Limpiar</button>
                            <button class="btn btn-sm btn-primary" type="button" onclick="applyScenarioFilters()">Aplicar Filtros</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="aiScenarioAnalysis" class="ai-narrative-box d-none">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h6 class="fw-bold"><i class="bi bi-stars text-warning me-2"></i>Análisis de Escenario (IA)</h6>
                <div id="aiNarrativeContent"></div>
            </div>
            <button class="btn-close" onclick="$('#aiScenarioAnalysis').addClass('d-none')"></button>
        </div>
    </div>

    <div class="card bg-light border-0">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary fw-bold" id="scenarioTitle">Canvas de Escenario</h5>
            <div id="scenarioActions" class="d-none text-end">
                <button class="btn btn-outline-primary btn-sm" onclick="generateScenarioAnalysis()">
                    <i class="bi bi-lightning-fill text-warning"></i> Analizar Estrategia
                </button>
                <button class="btn btn-outline-primary btn-sm mx-1" onclick="shareScenario()">
                    <i class="bi bi-share"></i> Compartir
                </button>
                <button class="btn btn-outline-secondary btn-sm mx-1" onclick="openAddWidget()">
                    <i class="bi bi-plus-circle"></i> Añadir Reporte
                </button>
                <button class="btn btn-primary btn-sm shadow-sm" onclick="saveLayout()">
                    <i class="bi bi-save"></i> Guardar Diseño
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="grid-stack scenario-canvas" id="scenarioGrid"></div>
        </div>
    </div>
</div>

<!-- Vista de Funciones (Solo Admin) -->
<div class="container d-none" id="actionsView">
    <div class="d-flex justify-content-between align-items-center mb-4">
         <h4 class="text-primary fw-bold mb-0"><i class="bi bi-play-circle-fill me-2"></i>Librería de Funciones Personalizadas</h4>
         <button class="btn btn-primary admin-only" onclick="openActionModal()">
            <i class="bi bi-plus-lg me-1"></i> Nueva Función
         </button>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="actionsTable">
                    <thead class="bg-light">
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Categoría</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="actionsListBody">
                        <!-- JS generated -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="container d-none" id="db_connectionsView">
    <div class="d-flex justify-content-between align-items-center mb-4">
         <h4 class="text-primary fw-bold mb-0"><i class="bi bi-database me-2"></i>Fuentes de Datos (SQL)</h4>
         <button class="btn btn-primary admin-only shadow-sm" onclick="openDbConnectionModal()">
            <i class="bi bi-plus-lg me-1"></i> Nueva Conexión SQL
         </button>
    </div>
    
    <div class="card shadow-sm border-0 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Conexión</th>
                            <th>Host / Database</th>
                            <th>Estado</th>
                            <th>Usuario</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="dbConnectionsList">
                        <!-- JS generated -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="container d-none" id="servicesView">
    <div class="d-flex justify-content-between align-items-center mb-4">
         <h4 class="text-primary fw-bold mb-0"><i class="bi bi-cpu-fill me-2"></i>Servicios Externos (IA, Email, etc.)</h4>
         <button class="btn btn-primary admin-only shadow-sm" onclick="openConnectionModal()">
            <i class="bi bi-plus-lg me-1"></i> Nuevo Servicio
         </button>
    </div>

    <div class="card shadow-sm border-0 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Servicio</th>
                            <th>Configuración</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="connectionsList">
                        <!-- JS content managed by connections.js -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="container d-none" id="tablesView">
    <div class="row">
        <!-- Sidebar: List of Tables -->
        <div class="col-md-3">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-columns-reverse me-1"></i>Mis Tablas</span>
                    <button class="btn btn-sm btn-primary rounded-circle shadow-sm" onclick="openTableCreator()" title="Nueva Tabla">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="list-group list-group-flush" id="tablesList">
                    <!-- List items generated by tables.js -->
                    <div class="text-center p-3 text-muted small">Cargando tablas...</div>
                </div>
            </div>
        </div>

        <!-- Main: Table Editor -->
        <div class="col-md-9">
            <div class="card shadow-sm border-0" style="min-height: 500px;">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3" id="tableHeaderPanel">
                    <div>
                        <h5 class="mb-0 fw-bold text-dark" id="currentTableName">Seleccione una tabla</h5>
                        <small class="text-muted d-none" id="currentTableSchema">Esquema: ...</small>
                    </div>
                    <div id="tableActions" class="d-none">
                        <input type="file" id="importExcelInput" class="d-none" accept=".xlsx, .xls, .csv">
                        <button class="btn btn-sm btn-outline-secondary me-2" onclick="$('#importExcelInput').click()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Importar Excel
                        </button>
                        <button class="btn btn-sm btn-outline-primary me-2" onclick="openColumnEditor()">
                            <i class="bi bi-gear me-1"></i> Estructura
                        </button>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                Más
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item text-danger" href="#" onclick="deleteCurrentTable()"><i class="bi bi-trash me-2"></i>Eliminar Tabla</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Advanced Search Toolbar -->
                <div class="bg-white p-2 border-bottom d-none" id="tableSearchToolbar">
                    <div class="row g-2 align-items-center">
                        <div class="col-auto">
                            <span class="text-muted small fw-bold"><i class="bi bi-search me-1"></i>Buscar en:</span>
                        </div>
                        <div class="col-auto">
                            <select class="form-select form-select-sm" id="searchColumnSelect" style="width: 150px;">
                                <!-- Populated dynamically -->
                            </select>
                        </div>
                        <div class="col" id="searchInputContainer">
                            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Escriba para buscar...">
                        </div>
                         <div class="col-auto">
                            <button class="btn btn-sm btn-secondary" onclick="resetTableSearch()">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Toolbar for Adding Rows -->
                <div class="bg-light p-2 border-bottom d-none justify-content-between align-items-center" id="tableDataToolbar">
                    <div>
                        <button class="btn btn-sm btn-primary" onclick="openRowEditor()">
                            <i class="bi bi-plus-lg me-1"></i> Nuevo Registro
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="reloadTableData()">
                            <i class="bi bi-arrow-clockwise"></i> Recargar
                        </button>
                    </div>
                    <div>
                         <span class="badge bg-info text-dark" id="rowCountBadge">0 filas</span>
                    </div>
                </div>

                <div class="card-body p-0 table-responsive position-relative">
                    <table class="table table-striped table-hover mb-0 w-100" id="customTableGrid" style="font-size: 0.85rem;">
                        <!-- DataTables generated here -->
                    </table>
                    <div id="tableLoading" class="position-absolute top-50 start-50 translate-middle d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Create Table -->
<div class="modal fade" id="createTableModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nueva Tabla de Datos (Tipo n8n)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <label class="form-label fw-bold">Nombre de la Tabla (Identificador)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light font-monospace">tb_</span>
                        <input type="text" class="form-control font-monospace" id="newTableName" placeholder="presupuestos_2024" oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '')">
                    </div>
                    <small class="text-muted">Se usará como nombre real en la base de datos (MySQL). Solo letras minúsculas, números y guiones bajos.</small>
                </div>
                
                <h6 class="fw-bold border-bottom pb-2">Definición de Columnas</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-borderless">
                        <thead class="text-muted small uppercase">
                            <tr>
                                <th style="width: 40%">Nombre Columna</th>
                                <th style="width: 40%">Tipo de Dato</th>
                                <th style="width: 20%">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="newTableColumns">
                            <!-- Dynamic Rows -->
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-sm btn-outline-primary dashed-border w-100 py-2" onclick="addColPending()">
                    <i class="bi bi-plus-lg"></i> Agregar Campo
                </button>
                
                <div class="alert alert-info mt-3 d-flex align-items-center small">
                    <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                    <div>
                        Las columnas <code>id</code>, <code>created_at</code> y <code>updated_at</code> se crean automáticamente.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="createCustomTable()">Crear Tabla</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Row Editor (Add/Edit Data) -->
<div class="modal fade" id="rowEditorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rowEditorTitle">Registro</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="rowEditorForm">
                    <input type="hidden" name="id" id="rowEditId">
                    <div id="rowEditorFields"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="saveRowData()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Button for AI Config in Header (Inject via JS or add statically, will add statically) -->
<!-- Modal Editor -->
<div class="modal fade" id="editorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="editorModalLabel">Editar Reporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-grid gap-2 mb-3">
                    <button class="btn btn-warning text-dark font-weight-bold" type="button" id="btnAiAssist">
                        <i class="bi bi-stars"></i> Asistente IA (Generar SQL)
                    </button>
                </div>
                <form id="editorForm">
                    <input type="hidden" id="editId" name="id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Código (Único)</label>
                            <input type="text" class="form-control" id="editCode" name="code" required>
                        </div>
                        <div class="mb-3 col-md-4">
                            <label class="form-label">Categoría</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="category" id="editCategory" list="categoryOptions">
                            </div>
                            <datalist id="categoryOptions">
                                <option value="Ventas">
                                <option value="Inventario">
                                <option value="Gerencia">
                                <option value="Integraciones">
                            </datalist>
                        </div>
                        <div class="col-md-2 d-flex align-items-center">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" value="1" name="is_view" id="editIsView">
                                <label class="form-check-label" for="editIsView">Es Vista</label>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-center">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" value="1" name="is_active" id="editIsActive" checked>
                                <label class="form-check-label" for="editIsActive">Activo</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nombre del Reporte</label>
                            <input type="text" class="form-control" id="editName" name="name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" id="editDesc" name="description" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="editIsAutomatic" name="is_automatic">
                                <label class="form-check-label fw-bold" for="editIsAutomatic">
                                    <i class="bi bi-robot me-1"></i> Ejecución Automática (Programada)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Frecuencia (Minutos)</label>
                            <input type="number" class="form-control" id="editCronInterval" name="cron_interval_minutes" min="1" value="60">
                            <small class="text-muted">Cada cuánto tiempo el robot re-ejecuta el reporte.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Consulta SQL (Base)</label>
                            <textarea class="form-control font-monospace" id="editSql" name="sql_query" rows="5" required></textarea>
                            <small class="text-muted">Use marcadores convencionales. Los filtros dinámicos se inyectarán.</small>
                        </div>
                         <div class="col-12">
                            <label class="form-label text-danger">Script PHP (Post-Procesamiento)</label>
                            <textarea class="form-control font-monospace" id="editPhp" name="php_script" rows="4" style="background-color: #fff5f5;"></textarea>
                            <small class="text-muted">Ejecuta código nativo después de la consulta. Variable disponible: <code>$results</code> (array referenciado).</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-success"><i class="bi bi-play-circle"></i> Script PHP Post-Procesamiento (Acción)</label>
                            <textarea class="form-control font-monospace" id="editPhp2" name="phpscript2" rows="4" style="background-color: #f0fff4;"></textarea>
                            <small class="text-muted">Se ejecuta al presionar "Procesar" después de revisar resultados. Variables: <code>$results</code>, <code>$pdo</code>. Ej: guardar en tabla, enviar email, webhook.</small>
                        </div>
                        <div class="col-12 border-top pt-3 mt-3">
                            <h6 class="text-primary fw-bold"><i class="bi bi-play-circle-fill"></i> Función Predefinida (Secure FaaS)</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Ejecutar esta Función:</label>
                                    <select class="form-select border-primary" id="editPostActionCode" name="post_action_code">
                                        <option value="">-- Ninguna --</option>
                                        <!-- Opciones cargadas por JS -->
                                    </select>
                                    <small class="text-muted">Se ejecutará después de filtrar y procesar los resultados SQL.</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Parámetros de la Función (JSON)</label>
                                    <textarea class="form-control font-monospace font-small" id="editPostActionParams" name="post_action_params" rows="3" placeholder='{ "token": "...", "chat_id": "..." }'></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">JSON Columnas (Headers)</label>
                            <textarea class="form-control font-monospace" id="editCols" name="columns_json" rows="2">[]</textarea>
                            <small class="text-muted">Ej: ["ID", "Nombre", "Fecha"]</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">JSON Parámetros (Filtros)</label>
                            <textarea class="form-control font-monospace" id="editParams" name="parameters_json" rows="4">[]</textarea>
                            <small class="text-muted">Ej: [{"type": "date_range", "field": "fecha", "label": "Rango"}]</small>
                        </div>
                        <div class="col-12 bg-light p-3 rounded border">
                            <h6 class="text-primary fw-bold mb-2"><i class="bi bi-shield-lock-fill me-2"></i>Control de Acceso (ACL)</h6>
                            <div class="row g-2">
                                <div class="col-md-12">
                                    <label class="form-label small fw-bold text-uppercase">¿Quién puede ver este reporte?</label>
                                    <input type="text" class="form-control" id="editAclView" placeholder="Ej: admin, viewer, U:jperez">
                                    <div class="form-text small">Separa por comas. Vacío = Público para todos los registrados. (Roles: <code>admin</code>, <code>viewer</code>. Usuarios: <code>U:codigo</code>)</div>
                                </div>
                            </div>
                        </div>
                        <hr class="my-3">
                        <div class="col-12">
                            <h6 class="text-primary"><i class="bi bi-printer"></i> Configuración de Impresión / Agrupación</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Agrupar por Columna (Nombre)</label>
                                    <input type="text" class="form-control" id="editGroupCol" placeholder="Ej: Cliente">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Columnas a Sumar (Separadas por coma)</label>
                                    <input type="text" class="form-control" id="editSumCols" placeholder="Ej: Total, Subtotal">
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mt-2">
                            <h6 class="text-success"><i class="bi bi-bar-chart"></i> Configuración de Gráfico</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Columna para Etiquetas (Label)</label>
                                    <input type="text" class="form-control" id="editChartLabelCol" placeholder="Ej: Producto">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Columna para Valores (Value)</label>
                                    <input type="text" class="form-control" id="editChartValueCol" placeholder="Ej: Total">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="saveReport()">Guardar Cambios</button>
            </div>
        </div>
    </div>
</div>

    </div>
</div>


<!-- Modal AI Prompt (User Input) -->
<div class="modal fade" id="aiPromptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-stars text-warning me-2"></i>Asistente de Creación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Describe el reporte que necesitas en lenguaje natural. Sé específico con los filtros, ordenamiento y columnas que deseas.
                </p>
                <div class="form-floating">
                    <textarea class="form-control" placeholder="Describe tu reporte..." id="aiUserPrompt" style="height: 150px; border-radius: 15px;"></textarea>
                    <label for="aiUserPrompt">Ej: Ventas del último mes agrupadas por cliente...</label>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-dark px-4" id="btnRunAiGeneration">
                    <i class="bi bi-lightning-fill text-warning"></i> Generar Reporte
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Change Password -->
<div class="modal fade" id="passwordChangeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Cambiar Contraseña Maestra</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="passwordChangeForm">
                    <div class="mb-3">
                        <label class="form-label">Contraseña Actual</label>
                        <input type="password" class="form-control" id="oldPassword" required>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label">Nueva Contraseña</label>
                        <input type="password" class="form-control" id="newPassword" required>
                        <small class="text-muted">Mínimo 4 caracteres.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirmar Nueva Contraseña</label>
                        <input type="password" class="form-control" id="confirmPassword" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="changePassword()">Guardar Cambios</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Editor de Acciones -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title font-monospace" id="actionModalLabel">ActionEditor // FaaS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <form id="actionForm">
                    <input type="hidden" id="actionId">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase">Código Único (Invocación)</label>
                                        <input type="text" class="form-control font-monospace" id="actionCode" placeholder="UTIL_SEND_TELEGRAM" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase">Nombre de Función</label>
                                        <input type="text" class="form-control" id="actionName" placeholder="Enviar a Telegram" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase">Categoría</label>
                                        <input type="text" class="form-control" id="actionCategory" placeholder="Comunicación">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase">Esquema de Parámetros (JSON)</label>
                                        <textarea class="form-control font-monospace small" id="actionSchema" rows="6" placeholder='{ "token": { "type": "string", "required": true } }'></textarea>
                                        <div class="form-text mt-1">Define los inputs que la IA y el sistema enviarán.</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="actionIsActive" checked>
                                        <label class="form-check-label fw-bold">Estado: ACTIVO</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label fw-bold mb-0">Lógica PHP (Sandbox Isolated)</label>
                                            <span class="badge bg-soft-info text-info">Closure context: $results, $params, $context</span>
                                        </div>
                                        <textarea id="actionPhpRaw" class="d-none"></textarea>
                                        <div id="actionPhpEditor" style="height: 350px; border: 1px solid #dee2e6; border-radius: 4px;"></div>
                                    </div>
                                    <div>
                                        <label class="form-label fw-bold small text-uppercase">Descripción para la IA</label>
                                        <textarea class="form-control" id="actionDesc" rows="2" placeholder="Explica detalladamente qué hace y qué parámetros usa para que la IA la entienda."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-white border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                <div class="vr mx-2"></div>
                <button type="button" class="btn btn-warning" onclick="testAction()">
                    <i class="bi bi-bug me-1"></i> Dry Run
                </button>
                <button type="button" class="btn btn-primary" onclick="saveAction()">
                    <i class="bi bi-save me-1"></i> Guardar Acción
                </button>
            </div>
        </div>
    </div>
</div>

<!-- CodeMirror -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/rowgroup/1.3.1/js/dataTables.rowGroup.min.js"></script>
<!-- jQuery UI -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/jquery-ui.min.js"></script>
<!-- PivotTable.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.es.min.js"></script>
<!-- Plotly.js -->
<script src="https://cdn.plot.ly/plotly-basic-latest.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/plotly_renderers.min.js"></script>
<!-- Gridstack.js -->
<script src="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack-all.js"></script>
<!-- Modal Connection Editor -->
<div class="modal fade" id="connectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="connectionModalLabel">Nueva Conexión</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="connForm">
                    <input type="hidden" id="connId">
                    <div class="mb-3">
                        <label class="form-label">Nombre (Alias)</label>
                        <input type="text" class="form-control" id="connName" placeholder="Ej: Correo Ventas, IA Principal..." required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Tipo de Conexión</label>
                            <select class="form-select" id="connType" onchange="ConnectionsManager.renderFormFields()">
                                <option value="SMTP">Correo SMTP</option>
                                <option value="AI">Inteligencia Artificial</option>
                                <option value="TELEGRAM">Telegram Bot</option>
                                <option value="N8N">n8n / Webhook</option>
                                <option value="DRIVE">Google Drive (Próximamente)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sub-Proveedor (Opcional)</label>
                            <input type="text" class="form-control" id="connProvider" placeholder="gmail, openai...">
                        </div>
                    </div>
                    
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="connIsActive" checked>
                        <label class="form-check-label" for="connIsActive">Conexión Activa</label>
                    </div>
                    
                    <hr>
                    <h6 class="text-primary mb-3">Configuración Específica</h6>
                    <div id="dynamicFieldsContainer">
                        <!-- Injected by JS -->
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="ConnectionsManager.save()">Guardar</button>
            </div>
        </div>
    </div>
</div>


<!-- VISTA GESTIÓN DE USUARIOS -->
<div class="container d-none" id="usersView">
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="m-0 font-weight-bold text-primary"><i class="bi bi-people-fill me-2"></i>Gestión de Usuarios y Roles</h5>
                <button class="btn btn-primary" onclick="UsersManager.openEditor()">
                    <i class="bi bi-person-plus-fill me-2"></i>Nuevo Usuario
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="usersTable">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Atributos (Contexto)</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Editor Usuario -->
<div class="modal fade" id="userEditorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Editor de Usuario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="userId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Código de Usuario / Login</label>
                            <input type="text" class="form-control" id="userCode" placeholder="Ej: jperez" required>
                            <div class="form-text">Usado para iniciar sesión.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nombre Completo</label>
                            <input type="text" class="form-control" id="userRealName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contraseña</label>
                            <input type="password" class="form-control" id="userPassword" placeholder="******">
                            <div class="form-text text-warning small" id="passHelpText">Dejar vacío para no cambiar.</div>
                        </div>
                         <div class="col-md-6">
                            <label class="form-label">Rol del Sistema</label>
                            <select class="form-select" id="userRole">
                                <option value="viewer">Visualizador (Viewer)</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mt-4">
                            <label class="form-label fw-bold">Atributos de Contexto (JSON)</label>
                            <div class="alert alert-light border small">
                                Define variables para filtros automáticos. Ej: <code>{"bodega": "01", "zona": "NORTE"}</code>
                            </div>
                            <!-- Simple Key-Value Editor could be better, but JSON is flexible for MVP -->
                            <textarea class="form-control font-monospace" id="userAttributes" rows="3" placeholder='{ "bodega": "01" }'></textarea>
                        </div>

                        <div class="col-12 mt-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="userIsActive" checked>
                                <label class="form-check-label" for="userIsActive">Usuario Activo (Puede iniciar sesión)</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="UsersManager.save()">Guardar Usuario</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="dbConnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nueva Conexión SQL</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="dbConnForm">
                    <input type="hidden" id="dbConnId">
                    <div class="mb-3">
                        <label class="form-label">Nombre (Alias)</label>
                        <input type="text" class="form-control" id="dbConnName" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Motor</label>
                            <select class="form-select" id="dbConnType">
                                <option value="mysql">MySQL</option>
                                <option value="pgsql">PostgreSQL</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Host</label>
                            <input type="text" class="form-control" id="dbConnHost" placeholder="localhost" required>
                        </div>
                    </div>
                     <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Puerto</label>
                            <input type="number" class="form-control" id="dbConnPort" value="3306">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Base de Datos</label>
                            <input type="text" class="form-control" id="dbConnDbName" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Usuario</label>
                            <input type="text" class="form-control" id="dbConnUser" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contraseña</label>
                            <input type="password" class="form-control" id="dbConnPass">
                            <small class="text-muted small">Dejar en blanco para conservar actual si edita.</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Schema (Opcional, para Postgres)</label>
                        <input type="text" class="form-control" id="dbConnSchema" placeholder="public">
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="dbConnIsActive">
                        <label class="form-check-label">Conexión Activa</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" onclick="DbConnectionsManager.test()">Probar</button>
                <button type="button" class="btn btn-primary" onclick="DbConnectionsManager.save()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- VISTA BITÁCORA DE ACCIONES -->
<div class="container d-none" id="logsView">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="m-0 font-weight-bold text-primary"><i class="bi bi-journal-text me-2"></i>Bitácora de Acciones y Auditoría</h5>
                <button class="btn btn-outline-primary btn-sm" onclick="LogsManager.load()">
                    <i class="bi bi-arrow-clockwise me-1"></i> Actualizar
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle" id="logsTable">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th>Fecha</th>
                            <th>Origen</th>
                            <th>Reporte / Acción</th>
                            <th>Usuario</th>
                            <th>Estado</th>
                            <th>Mensaje / Detalles</th>
                            <th>Duración</th>
                        </tr>
                    </thead>
                    <tbody class="small"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Logic -->
<script src="assets/js/app.js"></script>
<script src="assets/js/scenarios.js"></script>
<script src="assets/js/tables.js"></script>
<script src="assets/js/db_connections.js"></script>
<script src="assets/js/connections.js"></script>
<script src="assets/js/actions.js"></script>
<script src="assets/js/users.js"></script>
<script src="assets/js/logs.js"></script>
<!-- SheetJS -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>

<script>
    $(document).ready(function() {
        console.log("Kyvid Flow Iniciado.");
        loadUserTheme();
        
        // --- PUBLIC SHARE MODE CHECK ---
        const urlParams = new URLSearchParams(window.location.search);
        const shareToken = urlParams.get('token');
        
        if (shareToken) {
            // Hide everything except the report view
            $('header.main-header, .nav-tabs, #reportsView > .card:first-child, #filterCard').addClass('d-none');
            // Call special loader in app.js
            if (typeof loadSharedReport === 'function') {
                loadSharedReport(shareToken);
            }
        }

        const scenarioToken = urlParams.get('stoken');
        if (scenarioToken) {
            // Switch to scenarios view and hide other things
            switchMainTab('scenarios');
            $('header.main-header, .nav-tabs, #scenariosView > .card:first-child').addClass('d-none');
            // Call loader in scenarios.js
            if (typeof loadSharedScenario === 'function') {
                loadSharedScenario(scenarioToken);
            }
        }
    });

    // Theme Management Logic
    function loadUserTheme() {
        $.getJSON('api/themes.php?action=get', function(resp) {
            if (resp.success && resp.theme) {
                applyTheme(resp.theme);
            }
        });
    }

    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme') || 'blue';
        let next = 'blue';
        
        if (current === 'blue') next = 'green';
        else if (current === 'green') next = 'dark';
        else if (current === 'dark') next = 'blue';
        
        applyTheme(next);
        
        // Save preference if Admin
        $.ajax({
            url: 'api/themes.php?action=save',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ theme: next })
        });
    }

    function applyTheme(themeName) {
        document.documentElement.setAttribute('data-theme', themeName);
        
        // Update Chart colors if needed (Optional reload)
        // If dark mode, charts might need white text, but for MVP we skip
    }
</script>

</body>
</html>
