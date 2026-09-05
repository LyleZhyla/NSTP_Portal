(function () {
    'use strict';
    window.QuizFocusMonitor = function (options) {
        const key = 'quiz-focus-' + options.responseId;
        let pending = [], count = options.count, away = false, stopped = false, sending = null;
        try { pending = JSON.parse(sessionStorage.getItem(key) || '[]'); } catch (_) {}
        if (!Array.isArray(pending)) pending = [];
        pending = pending.filter(item => item && !options.seen.includes(item.event_id));
        function persist() { try { sessionStorage.setItem(key, JSON.stringify(pending)); } catch (_) {} }
        function warning() {
            const total = count + pending.length;
            if (total) options.warn('Focus violation recorded (' + total + '). Please remain on the quiz tab.');
        }
        async function drain() {
            if (sending) { await sending; return drain(); }
            if (!pending.length || stopped) return;
            sending = (async () => {
                while (pending.length && !stopped) {
                    const item = pending[0];
                    const result = await options.send(item);
                    count = result.violations;
                    pending.shift(); persist();
                    warning();
                }
            })();
            try { await sending; } finally { sending = null; }
        }
        function retry() { drain().catch(() => options.warn('Focus violation not yet saved. Reconnecting automatically; keep the quiz open.')); }
        function leave() {
            if (stopped || away) return;
            away = true;
            const event_id = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
            pending.push({event_id, answers: structuredClone(options.collect())}); persist(); warning(); retry();
        }
        function resume() { if (!document.hidden && document.hasFocus()) away = false; }
        function visibility() { if (document.hidden) leave(); else resume(); }
        window.addEventListener('blur', leave);
        window.addEventListener('focus', resume);
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('online', retry);
        const timer = setInterval(retry, 3000);
        warning(); retry();
        return {
            flush: async () => { await drain(); },
            stop: () => {
                stopped = true; clearInterval(timer);
                window.removeEventListener('blur', leave); window.removeEventListener('focus', resume);
                document.removeEventListener('visibilitychange', visibility); window.removeEventListener('online', retry);
            }
        };
    };
})();
