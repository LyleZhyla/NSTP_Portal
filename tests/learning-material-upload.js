const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

async function scenario(components, levels) {
    const handlers = {}, calls = [];
    const control = () => ({disabled:false, addEventListener:()=>{}, setCustomValidity:()=>{}});
    const controls = Object.fromEntries(['material-file','material-upload-button','material-upload-cancel','material-upload-status','material-upload-progress','material-title','material-description'].map(id => [id,control()]));
    controls['material-file'].files = [{name:'lesson.mp4',size:4,slice:()=>new Blob(['test'])}];
    controls['material-title'].value = 'Code of Citizenship';
    controls['material-description'].value = '';
    const audience = {disabled:false};
    const form = {
        action:'endpoint/upload-learning-material.php', dataset:{maxSize:'10737418240'}, elements:{csrf_token:{value:'token'}},
        reportValidity:()=>true, addEventListener:(name, fn)=>handlers[name]=fn,
        querySelector:()=>audience,
        querySelectorAll:selector=>{
            assert.equal(audience.disabled,false,'selections read before locking the fieldset');
            return (selector.includes('component')?components:levels).map(value=>({value}));
        }
    };
    const window = {addEventListener:()=>{}, location:{assign:()=>{}},readMaterialJsonResponse:async response=>JSON.parse(await response.text())};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../include/learning-material-upload.js'),'utf8'), {
        window, FormData, AbortController, Event,
        document:{getElementById:id=>id==='material-upload-form'?form:controls[id], dispatchEvent:()=>{}},
        setTimeout:()=>1,clearTimeout:()=>{},setInterval:()=>1,clearInterval:()=>{},
        fetch:async (_, options)=>{
            const body = options.body, action = body.get('action'); calls.push(body);
            assert.equal(audience.disabled,true,'fields locked during request');
            return {ok:true,status:200,text:async()=>JSON.stringify(action==='start'?{upload_id:'test',chunk_size:4,received:0}:action==='chunk'?{received:4}:{success:true})};
        }
    });
    await handlers.submit({preventDefault:()=>{}});
    return {calls,status:controls['material-upload-status'].textContent};
}
(async()=>{
    let result=await scenario(['ROTC'],['MS-1','MS-31','MS-41']);
    assert.equal(result.calls.length,3,'start, chunk and finish complete');
    assert.deepEqual(result.calls[0].getAll('components[]'),['ROTC']);
    assert.deepEqual(result.calls[0].getAll('rotc_levels[]'),['MS-1','MS-31','MS-41']);
    result=await scenario(['CWTS','ROTC'],['MS-31']);
    assert.deepEqual(result.calls[0].getAll('components[]'),['CWTS','ROTC']);
    result=await scenario(['CWTS'],['MS-1']);
    assert.deepEqual(result.calls[0].getAll('rotc_levels[]'),[]);
    result=await scenario([],[]); assert.equal(result.calls.length,0);
    result=await scenario(['ROTC'],[]); assert.equal(result.calls.length,0);
    console.log('PASS upload metadata retains selected components/MS levels before locking; invalid audiences send no request');
})().catch(error=>{console.error(error);process.exitCode=1;});
