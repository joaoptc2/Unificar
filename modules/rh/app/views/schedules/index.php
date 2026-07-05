<div class="page-header">
    <h1><i class="bi bi-calendar3 me-2"></i>Agenda</h1>
    <div class="d-flex gap-2">
        <?php if (Auth::can('schedules', 'create')): ?>
            <a href="index.php?page=schedules&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Novo Compromisso
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div id="calendar" style="min-height:600px;"></div>
    </div>
</div>

<!-- Modal de detalhes -->
<div class="modal fade" id="eventModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eventModalTitle">Compromisso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong>Data:</strong> <span id="eventModalDate">-</span></p>
                <p class="mb-2"><strong>Funcionário:</strong> <span id="eventModalEmployee">-</span></p>
                <p class="mb-2"><strong>Tipo:</strong> <span id="eventModalType">-</span></p>
                <p class="mb-0" id="eventModalDescBlock" style="display:none;">
                    <strong>Descrição:</strong>
                    <span id="eventModalDesc"></span>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <a href="#" id="eventModalEditBtn" class="btn btn-primary"
                   <?= Auth::can('schedules', 'edit') ? '' : 'style="display:none;"' ?>>
                    <i class="bi bi-pencil me-1"></i> Editar
                </a>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('calendar');
    if (!el) return;

    const calendar = new FullCalendar.Calendar(el, {
        locale: 'pt-br',
        initialView: 'dayGridMonth',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        },
        buttonText: {
            today: 'Hoje', month: 'Mês', week: 'Semana', day: 'Dia', list: 'Lista',
        },
        height: 'auto',
        nowIndicator: true,
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        events: {
            url: 'index.php?page=schedules&action=events',
            method: 'GET',
            failure: function () {
                console.error('Falha ao carregar eventos.');
            },
        },
        dateClick: function (info) {
            <?php if (Auth::can('schedules', 'create')): ?>
            window.location.href = 'index.php?page=schedules&action=create&date=' + info.dateStr;
            <?php endif; ?>
        },
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            const ev = info.event;
            const props = ev.extendedProps;
            document.getElementById('eventModalTitle').textContent = ev.title;
            document.getElementById('eventModalDate').textContent =
                ev.start ? ev.start.toLocaleString('pt-BR') : '-';
            document.getElementById('eventModalEmployee').textContent = props.employee || '-';
            document.getElementById('eventModalType').textContent = props.type || '-';
            const descBlock = document.getElementById('eventModalDescBlock');
            const descEl = document.getElementById('eventModalDesc');
            if (props.description) {
                descEl.textContent = props.description;
                descBlock.style.display = '';
            } else {
                descBlock.style.display = 'none';
            }
            const editBtn = document.getElementById('eventModalEditBtn');
            if (editBtn) editBtn.href = props.editUrl;
            new bootstrap.Modal(document.getElementById('eventModal')).show();
        },
    });
    calendar.render();
});
</script>
