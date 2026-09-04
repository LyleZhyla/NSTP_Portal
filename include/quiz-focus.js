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
            if (total >= 3) { options.lock(); options.warn('Third focus violation. Automatically submitting your answers. Keep this page open until submission is confirmed.'); }
            else if (total) options.warn(total === 1 ? 'Warning 1 of 3: remain on the quiz tab.' : 'Final warning: the next tab/focus change will automatically submit your quiz.');
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
                    if (result.forced) { pending=[];persist();options.finish(result);return; }
                    warning();
                }
            })();
            try { await sending; } finally { sending = null; }
        }
        function retry() { drain().catch(() => options.warn('Focus violation not yet saved. Reconnecting automatically; keep the quiz open.')); }
        function leave() {
            if (stopped || away || count + pending.length >= 3) return;
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
            flush: async () => { await drain(); if (count >= 3) throw new Error('Your quiz was automatically submitted.'); },
            stop: () => {
                stopped = true; clearInterval(timer);
                window.removeEventListener('blur', leave); window.removeEventListener('focus', resume);
                document.removeEventListener('visibilitychange', visibility); window.removeEventListener('online', retry);
            }
        };
    };
})();
