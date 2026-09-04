const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { webcrypto } = require('node:crypto');
function surface() {
    const events = new Map();
    return {
        addEventListener: (name, fn) => { if (!events.has(name)) events.set(name, new Set()); events.get(name).add(fn); },
        removeEventListener: (name, fn) => events.get(name)?.delete(fn),
        fire: name => [...(events.get(name) || [])].forEach(fn => fn())
    };
}
(async () => {
    const window = surface(), document = surface(), storage = new Map();
    document.hidden = false; document.hasFocus = () => !document.hidden;
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../include/quiz-focus.js'), 'utf8'), {
        window, document, crypto: webcrypto, structuredClone,
        setInterval: () => 1, clearInterval: () => {},
        sessionStorage: {getItem: k => storage.get(k), setItem: (k,v) => storage.set(k,v)}
    });
    let requests = 0, locks = 0, finished = 0, fail = false;
    const seen = new Set();
    const options = {
        responseId: 42, count: 0, seen: [], collect: () => ({answer:'Saved work'}), warn: () => {},
        lock: () => locks++, finish: () => finished++,
        send: async item => {
            requests++;
            if (fail) throw Error('offline');
            seen.add(item.event_id);
            return {violations: seen.size, forced: seen.size >= 3, response_id:42};
        }
    };
    let monitor = window.QuizFocusMonitor(options);
    window.fire('blur'); document.hidden=true; document.fire('visibilitychange');
    await monitor.flush(); assert.equal(requests, 1, 'blur + visibility is one departure');
    document.hidden=false; window.fire('focus');
    fail=true; window.fire('blur');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(JSON.parse(storage.get('quiz-focus-42')).length, 1, 'offline event retained');
    monitor.stop(); fail=false;
    monitor=window.QuizFocusMonitor({...options,count:1,seen:[...seen]});
    await monitor.flush(); assert.equal(seen.size, 2, 'reload retries pending departure');
    window.fire('blur');
    await assert.rejects(monitor.flush(), /automatically submitted/);
    assert.equal(seen.size, 3); assert.equal(finished, 1); assert.ok(locks>0);
    monitor.stop(); window.fire('blur');
    assert.equal(seen.size, 3, 'stop removes listeners');
    console.log('PASS focus event deduplication, offline persistence, reload retry, third violation and cleanup');
})().catch(error => { console.error(error); process.exitCode=1; });
