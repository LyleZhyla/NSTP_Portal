(function () {
    'use strict';
    window.learningMaterialAudienceValues = function (form) {
        const components = Array.from(form.querySelectorAll('.audience-component:checked'), input => input.value);
        const levels = components.includes('ROTC') ? Array.from(form.querySelectorAll('.audience-level:checked'), input => input.value) : [];
        return {'components[]': components, 'rotc_levels[]': levels};
    };
    document.querySelectorAll('.material-audience').forEach(group => {
        const components = Array.from(group.querySelectorAll('.audience-component'));
        const levels = Array.from(group.querySelectorAll('.audience-level'));
        const rotc = group.querySelector('.audience-rotc');
        function update() {
            const selected = components.filter(input => input.checked);
            const showRotc = selected.some(input => input.value === 'ROTC');
            components[0].setCustomValidity(selected.length ? '' : 'Select at least one component.');
            rotc.hidden = !showRotc;
            rotc.disabled = !showRotc;
            levels[0].setCustomValidity(!showRotc || levels.some(input => input.checked) ? '' : 'Select at least one ROTC MS level.');
        }
        group.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('.material-audience-edit').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            if (button.disabled) return;
            const status = form.querySelector('.audience-save-status');
            const audience = window.learningMaterialAudienceValues(form);
            const body = new FormData(form);
            const group = form.querySelector('.material-audience');
            button.disabled = group.disabled = true;
            status.textContent = 'Saving...';
            try {
                const response = await fetch(form.action, {method: 'POST', body, credentials: 'same-origin'});
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'Unable to save audience.');
                const components = audience['components[]'];
                const label = components.join(', ') + (components.includes('ROTC') ? ' | ROTC students: ' + audience['rotc_levels[]'].join(', ') : '');
                form.closest('article').querySelector('.material-audience-label').textContent = 'Visible to: ' + label;
                status.textContent = 'Audience saved.';
            } catch (error) {
                status.textContent = error.message || 'Unable to save audience. Please retry.';
            } finally {
                button.disabled = group.disabled = false;
            }
        });
    });
})();
