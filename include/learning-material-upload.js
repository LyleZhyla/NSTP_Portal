(function () {
    'use strict';
    const form = document.getElementById('material-upload-form');
    if (!form) return;
    const fileInput = document.getElementById('material-file');
    const button = document.getElementById('material-upload-button');
    const cancel = document.getElementById('material-upload-cancel');
    const status = document.getElementById('material-upload-status');
    const progress = document.getElementById('material-upload-progress');
    const title = document.getElementById('material-title');
    const description = document.getElementById('material-description');
    let upload = null;
    let busy = false;
    let cancelled = false;
    let controller = null;
    button.disabled = false;

    async function send(action, values, retries = 0) {
        for (let attempt = 0; ; attempt++) {
            const body = new FormData();
            body.append('csrf_token', form.elements.csrf_token.value);
            body.append('action', action);
            Object.entries(values).forEach(([key, value]) => body.append(key, value));
            controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 90000);
            try {
                const response = await fetch(form.action, {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
                let data;
                try { data = await response.json(); }
                catch (_) { throw new Error('The server returned an invalid response. Please retry.'); }
                if (!response.ok) {
                    const error = new Error(data.message || 'Upload failed. Please retry.');
                    error.permanent = response.status < 500;
                    throw error;
                }
                return data;
            } catch (error) {
                if (cancelled || error.permanent || attempt >= retries) throw error;
                status.textContent = 'Connection interrupted. Retrying upload part...';
            } finally {
                clearTimeout(timeout);
            }
        }
    }
    function lockFields(locked) {
        fileInput.disabled = title.disabled = description.disabled = locked;
    }
    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];
        fileInput.setCustomValidity(file && (file.size === 0 || file.size > Number(form.dataset.maxSize)) ? 'Choose a non-empty file up to 10 GB.' : '');
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        const file = fileInput.files[0];
        if (!file || file.size === 0 || file.size > Number(form.dataset.maxSize)) return;
        busy = true;
        cancelled = false;
        button.disabled = true;
        cancel.hidden = false;
        cancel.disabled = false;
        progress.hidden = false;
        lockFields(true);
        document.dispatchEvent(new Event('nstp:upload-activity'));
        const heartbeat = setInterval(() => document.dispatchEvent(new Event('nstp:upload-activity')), 30000);
        try {
            if (!upload) {
                status.textContent = 'Preparing upload...';
                upload = await send('start', {title: title.value, description: description.value, name: file.name, size: file.size});
                if (!Number.isInteger(upload.chunk_size) || upload.chunk_size <= 0) throw new Error('The server upload limit is too small. Contact your administrator.');
            }
            while (!cancelled && upload.received < file.size) {
                const offset = upload.received;
                const data = await send('chunk', {
                    upload_id: upload.upload_id,
                    offset,
                    chunk: file.slice(offset, Math.min(offset + upload.chunk_size, file.size))
                }, 2);
                if (!Number.isInteger(data.received) || data.received <= offset || data.received > file.size) throw new Error('Unexpected upload progress. Please retry.');
                upload.received = data.received;
                progress.value = Math.floor(upload.received / file.size * 100);
                status.textContent = progress.value + '% uploaded';
            }
            if (!cancelled) {
                cancel.disabled = true;
                status.textContent = 'Upload complete. Validating and saving material...';
                await send('finish', {upload_id: upload.upload_id}, 2);
                upload = null;
                busy = false;
                window.location.assign('learning-management.php?tab=learning-materials');
            }
        } catch (error) {
            if (!cancelled) {
                status.textContent = error.name === 'AbortError' ? 'Connection timed out. Click Retry Upload to continue.' : error.message;
                button.textContent = upload ? 'Retry Upload' : 'Upload Material';
                button.disabled = false;
                cancel.disabled = false;
                if (!upload) lockFields(false);
            }
        } finally {
            clearInterval(heartbeat);
            busy = false;
        }
    });
    cancel.addEventListener('click', async () => {
        cancelled = true;
        cancel.disabled = true;
        if (controller) controller.abort();
        // Wait until the active request's completion handler has settled.
        while (busy) await new Promise(resolve => setTimeout(resolve, 50));
        try {
            if (upload) await send('cancel', {upload_id: upload.upload_id});
            status.textContent = 'Upload cancelled.';
        } catch (_) {
            status.textContent = 'Upload stopped. Unfinished server files expire after 24 hours.';
        }
        upload = null;
        lockFields(false);
        cancel.hidden = true;
        progress.hidden = true;
        progress.value = 0;
        button.disabled = false;
        button.textContent = 'Upload Material';
    });
    window.addEventListener('beforeunload', event => {
        if (busy || upload) { event.preventDefault(); event.returnValue = ''; }
    });
})();
