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
        setInterval: () => 1, clearInterval: () => {}, setTimeout, clearTimeout,
        sessionStorage: {getItem: k => storage.get(k), setItem: (k,v) => storage.set(k,v)}
    });
    let requests = 0, fail = false;
    const seen = new Set();
    const options = {
        responseId: 42, count: 0, seen: [], collect: () => ({answer:'Saved work'}), warn: () => {},
        send: async item => {
            requests++;
            if (fail) throw Error('offline');
            seen.add(item.event_id);
            return {violations: seen.size, forced:false, response_id:42};
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
    await monitor.flush();
    assert.equal(seen.size, 3, 'third violation is recorded without automatic submission');
    document.hidden=false; window.fire('focus'); window.fire('blur');
    await monitor.flush(); assert.equal(seen.size, 4, 'violations continue to be recorded after the third');
    monitor.stop(); window.fire('blur');
    assert.equal(seen.size, 4, 'stop removes listeners');
    console.log('PASS focus event deduplication, offline persistence, reload retry, detection-only violations and cleanup');
})().catch(error => { console.error(error); process.exitCode=1; });
