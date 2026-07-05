/**
 * RH Hospital - JavaScript Principal
 */
document.addEventListener('DOMContentLoaded', function() {

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert-dismissible').forEach(function(alert) {
        setTimeout(function() {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });

    // Máscara de CPF
    document.querySelectorAll('[data-mask="cpf"]').forEach(function(input) {
        input.addEventListener('input', function(e) {
            var v = e.target.value.replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 9) {
                v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            } else if (v.length > 6) {
                v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            } else if (v.length > 3) {
                v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            }
            e.target.value = v;
        });
    });

    // Máscara de telefone
    document.querySelectorAll('[data-mask="phone"]').forEach(function(input) {
        input.addEventListener('input', function(e) {
            var v = e.target.value.replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 10) {
                v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
            } else if (v.length > 6) {
                v = v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
            } else if (v.length > 2) {
                v = v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
            }
            e.target.value = v;
        });
    });

    // Máscara de CEP
    document.querySelectorAll('[data-mask="cep"]').forEach(function(input) {
        input.addEventListener('input', function(e) {
            var v = e.target.value.replace(/\D/g, '');
            if (v.length > 8) v = v.substring(0, 8);
            if (v.length > 5) {
                v = v.replace(/(\d{5})(\d{1,3})/, '$1-$2');
            }
            e.target.value = v;
        });
    });

    // Confirmação de exclusão
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(el.getAttribute('data-confirm') || 'Tem certeza que deseja realizar esta ação?')) {
                e.preventDefault();
            }
        });
    });

    // Tooltip Bootstrap
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(el) {
        return new bootstrap.Tooltip(el);
    });

    // Preview de imagem no upload
    document.querySelectorAll('[data-preview]').forEach(function(input) {
        input.addEventListener('change', function(e) {
            var target = document.querySelector(input.getAttribute('data-preview'));
            if (target && e.target.files && e.target.files[0]) {
                var reader = new FileReader();
                reader.onload = function(ev) {
                    target.src = ev.target.result;
                    target.style.display = 'block';
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });
    });

    // Select2-like search for selects (simple implementation)
    document.querySelectorAll('.searchable-select').forEach(function(select) {
        // Add search functionality to select elements
        var wrapper = document.createElement('div');
        wrapper.className = 'position-relative';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
    });

    // Toggle sidebar on mobile
    var sidebarToggle = document.querySelector('[data-bs-target="#sidebar"]');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
        });
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        var sidebar = document.querySelector('.sidebar');
        var toggle = document.querySelector('[data-bs-target="#sidebar"]');
        if (sidebar && sidebar.classList.contains('show') &&
            !sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove('show');
        }
    });
});

/**
 * Função utilitária para formatar data
 */
function formatDate(dateStr) {
    if (!dateStr) return '-';
    var parts = dateStr.split('-');
    if (parts.length === 3) {
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }
    return dateStr;
}
