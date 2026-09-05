(function () {
    'use strict';
    window.readMaterialJsonResponse = async function (response) {
        const text = await response.text();
        try { return JSON.parse(text); }
        catch (_) {
            const title = /<title[^>]*>([^<]+)<\/title>/i.exec(text)?.[1]?.replace(/\s+/g, ' ').trim();
            const detail = title && title.length <= 120 ? ': ' + title : '';
            throw new Error('The server returned an HTML/non-JSON error (HTTP ' + response.status + ')' + detail + '. Reload and try again; if it continues, check the PHP/server log.');
        }
    };
})();
