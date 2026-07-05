/**
 * TeamChat — JavaScript global
 */
document.addEventListener('DOMContentLoaded', function () {

    // Auto-dismiss alerts after 5s
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Confirmação via data-confirm
    document.addEventListener('click', function (e) {
        var target = e.target.closest('[data-confirm]');
        if (target) {
            var msg = target.getAttribute('data-confirm');
            if (!confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        }
    });

    // Keyboard shortcut: Ctrl+K for search
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            var searchInput = document.getElementById('sidebarSearch');
            if (searchInput) {
                searchInput.focus();
            } else {
                var bu = document.querySelector('meta[name="base-url"]')?.content || ''; window.location.href = (bu ? bu + '/' : '') + 'index.php?m=chat&page=search';
            }
        }
    });

    // Auto-grow textareas
    document.querySelectorAll('textarea.message-input').forEach(function (ta) {
        ta.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 150) + 'px';
        });
    });

    // Process step actions (AJAX)
    document.querySelectorAll('.step-action-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var stepId = this.dataset.stepId;
            var status = this.dataset.status;
            var processId = this.dataset.processId;
            var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';

            var formData = new FormData();
            formData.append('step_id', stepId);
            formData.append('status', status);
            formData.append('process_id', processId);
            formData.append('_csrf_token', csrfToken);

            fetch(baseUrl + '/index.php?m=chat&page=processes&action=updateStep', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                }
            });
        });
    });

    // Kanban drag and drop (simple)
    if (typeof Sortable !== 'undefined') {
        document.querySelectorAll('.kanban-column-body').forEach(function (col) {
            new Sortable(col, {
                group: 'kanban',
                animation: 150,
                ghostClass: 'kanban-ghost',
                onEnd: function (evt) {
                    var taskId = evt.item.dataset.taskId;
                    var newStatus = evt.to.dataset.status;
                    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';

                    var formData = new FormData();
                    formData.append('id', taskId);
                    formData.append('status', newStatus);
                    formData.append('_csrf_token', csrfToken);

                    fetch(baseUrl + '/index.php?m=chat&page=tasks&action=updateStatus', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        body: formData
                    });
                }
            });
        });
    }
});
