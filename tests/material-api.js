const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const window = {};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../include/material-api.js'),'utf8'), {window});
(async()=>{
    const parsed=await window.readMaterialJsonResponse({status:200,text:async()=>JSON.stringify({success:true})});
    assert.equal(parsed.success,true);
    await assert.rejects(
        window.readMaterialJsonResponse({status:500,text:async()=> '<!DOCTYPE html><title>Internal Server Error</title>'}),
        error=>/HTML\/non-JSON/.test(error.message)&&/HTTP 500/.test(error.message)&&/Internal Server Error/.test(error.message)
    );
    await assert.rejects(window.readMaterialJsonResponse({status:503,text:async()=> 'Connection failed'}),/non-JSON error \(HTTP 503\)/);
    console.log('PASS material API parses JSON and reports HTML/plain server errors clearly');
})().catch(error=>{console.error(error);process.exitCode=1;});
