(function(){
    'use strict';
    const {e,sections,question,read,validate,shuffle}=window.QuizUI;
    function play(ctx,loaded,preview=false){
        const app=ctx.app,d=loaded.definition;let answers=loaded.response?.answers||{},editing=loaded.response?.state==='submitted',done=false,changed=false,saveTimer=null,queue=Promise.resolve(),uploads=0,version=0,heartbeat=null,focusMonitor=null;
        app.style.setProperty('--quiz-accent',d.accent);
        function header(){return `<div class="card quiz-top"><div class="card-body"><h1 class="h3">${e(d.title)}</h1><p class="quiz-help">${e(d.description)}</p>${d.monitor_focus&&!preview?'<div class="alert alert-warning">Tab/focus monitoring is enabled. First departure: warning; second: final warning; third: automatic submission of your current answers. Avoid switching tabs or apps. System dialogs may also cause loss of focus.</div>':''}${preview?'<div class="alert alert-info mb-0">Preview only. Answers and scores are not saved.</div>':`<p class="small quiz-muted mb-0">Your name is recorded from your student account. ${d.closes_at?'Closes '+e(d.closes_at.replace('T',' '))+' (Manila time).':''}</p>`}</div></div>`;}
        function stop(){focusMonitor?.stop();clearInterval(heartbeat);clearTimeout(saveTimer);}
        function render(){
            const blocks=sections(d);let current=0,history=[];
            if(d.shuffle_questions)blocks.forEach(block=>{const last=block.questions.at(-1);const branch=last&&Object.keys(last.next||{}).length;block.questions=branch?[...shuffle(block.questions.slice(0,-1)),last]:shuffle(block.questions);});
            app.innerHTML=header()+`<form id="quiz-response-form" novalidate>${blocks.map((block,index)=>`<section data-section="${e(block.id)}" ${index?'hidden':''}>${index?`<div class="card p-3"><h2 class="h4">${e(block.title)}</h2><p class="quiz-help mb-0">${e(block.help)}</p>${window.QuizUI.media(block)}</div>`:''}${block.questions.map(q=>question(q,answers[q.id],d.shuffle_options)).join('')}</section>`).join('')}<div class="quiz-toolbar"><button type="button" class="btn btn-outline-secondary" id="quiz-back">Back</button><span id="quiz-section-label" class="mr-auto"></span><button type="submit" class="btn btn-success" id="quiz-next">Next</button></div><p id="quiz-draft-status" class="small quiz-muted" role="status">${preview?'Preview':editing?'Editing your response. Submit changes to save.':'Draft autosave is ready.'}</p></form>`;
            const form=app.querySelector('form'),draftStatus=app.querySelector('#quiz-draft-status'),next=app.querySelector('#quiz-next'),back=app.querySelector('#quiz-back');
            function collect(){answers=read(form,d,answers);return answers;}
            function nextIndex(){let target=null;for(const q of blocks[current].questions){const answer=answers[q.id];if(typeof answer==='string'&&q.next?.[answer])target=q.next[answer];}if(target==='submit')return blocks.length;if(target){const index=blocks.findIndex(block=>block.id===target);return index>current?index:current+1;}return current+1;}
            function show(){form.querySelectorAll('[data-section]').forEach((el,index)=>el.hidden=index!==current);back.hidden=!history.length;app.querySelector('#quiz-section-label').textContent=`Section ${current+1} of ${blocks.length}`;next.textContent=nextIndex()>=blocks.length?(editing?'Submit changes':'Submit quiz'):'Next';}
            function autosave(){if(preview||editing||done)return;clearTimeout(saveTimer);const localVersion=version;saveTimer=setTimeout(()=>{const snapshot=structuredClone(collect());draftStatus.textContent='Saving draft...';queue=queue.catch(()=>{}).then(()=>ctx.api('draft',{id:ctx.id,answers:snapshot})).then(()=>{if(version===localVersion){changed=false;draftStatus.textContent='Draft saved.';}}).catch(error=>{draftStatus.textContent='Draft not saved: '+error.message;});},1000);}
            form.addEventListener('input',event=>{if(event.target.type==='file')return;changed=true;version++;collect();show();autosave();});
            form.addEventListener('change',async event=>{
                if(!event.target.dataset.fileQuestion)return;
                const input=event.target,file=input.files[0],qid=input.dataset.fileQuestion,feedback=input.closest('[data-question]').querySelector('.quiz-file-status');if(!file)return;
                if(preview){feedback.textContent='Selected for preview: '+file.name;answers[qid]={file_id:0};return;}
                if(file.size===0||file.size>window.quizBoot.fileLimit){input.value='';feedback.textContent='Choose a non-empty file within the displayed limit.';return;}
                uploads++;next.disabled=true;input.disabled=true;feedback.textContent='Uploading attachment...';
                try{const result=await ctx.upload(qid,file);answers[qid]={file_id:result.file_id};feedback.textContent='Uploaded: '+result.name;changed=true;version++;autosave();}catch(error){feedback.textContent=error.message;}finally{uploads--;next.disabled=uploads>0;input.disabled=false;}
            });
            back.addEventListener('click',()=>{collect();current=history.pop()??0;show();form.querySelectorAll('[data-section]')[current].scrollIntoView({behavior:'smooth',block:'start'});});
            form.addEventListener('submit',async event=>{event.preventDefault();if(uploads||next.disabled)return;collect();if(!validate(form.querySelectorAll('[data-section]')[current],d,answers))return;const target=nextIndex();if(target<blocks.length){history.push(current);current=target;show();form.querySelectorAll('[data-section]')[current].scrollIntoView({behavior:'smooth',block:'start'});return;}if(preview){app.innerHTML=header()+`<div class="card p-4"><h2 class="h4">Preview complete</h2><p>${e(d.confirmation)}</p><p class="quiz-muted">No response was saved.</p><button class="btn btn-outline-success" id="preview-restart">Try again</button></div>`;app.querySelector('#preview-restart').onclick=()=>{answers={};render();};return;}
                next.disabled=true;back.disabled=true;clearTimeout(saveTimer);draftStatus.textContent='Submitting response...';
                try{await queue;await focusMonitor?.flush();const result=await ctx.api('submit',{id:ctx.id,answers:collect()});done=true;changed=false;stop();location.href=`quiz.php?id=${ctx.id}&mode=result&response_id=${result.response_id}`;}catch(error){ctx.message(error.message);draftStatus.textContent='Not submitted. Your answers remain on this page.';next.disabled=false;back.disabled=false;}
            });
            show();
            if(!preview){
                if(d.monitor_focus) focusMonitor=window.QuizFocusMonitor({
                    seen:loaded.response?.focus_events||[],responseId:loaded.response?.response_id, count:loaded.response?.violations||0,
                    send:values=>ctx.api('focus_event',{id:ctx.id,...values}), collect,
                    warn:text=>ctx.message(text),
                    lock:()=>{clearTimeout(saveTimer);form.querySelectorAll('input,textarea,select,button').forEach(el=>el.disabled=true);},
                    finish:result=>{done=true;changed=false;stop();location.href=`quiz.php?id=${ctx.id}&mode=result&response_id=${result.response_id}`;}
                });
                heartbeat=setInterval(()=>document.dispatchEvent(new Event('nstp:upload-activity')),30000);window.addEventListener('beforeunload',event=>{if(changed&&!done){event.preventDefault();event.returnValue='';}});}
        }
        if(!preview&&loaded.accepting===false){app.innerHTML=header()+`<div class="card p-4"><h2 class="h4">Not accepting responses</h2><p>${e(loaded.accepting_message)}</p>${loaded.response?`<p>Your saved response is retained.</p><a class="btn btn-outline-success" href="quiz.php?id=${ctx.id}&mode=result&response_id=${loaded.response.response_id}">View my saved response</a>`:''}</div>`;return stop;}
        if(preview||loaded.response?.state==='draft'){render();return stop;}
        if(editing){app.innerHTML=header()+`<div class="card p-4"><h2 class="h4">Response submitted</h2><div class="quiz-actions"><a class="btn btn-success" href="quiz.php?id=${ctx.id}&mode=result&response_id=${loaded.response.response_id}">View my response</a>${d.allow_edit&&Number(loaded.response?.violations||0)<3?'<button class="btn btn-outline-success" id="edit-my-response">Edit response</button>':''}</div></div>`;app.querySelector('#edit-my-response')?.addEventListener('click',render);return stop;}
        app.innerHTML=header()+`<div class="card p-4"><p>${d.questions.filter(q=>q.type!=='section').length} questions. Required questions are marked with an asterisk.</p><button class="btn btn-success" id="quiz-start">Start Quiz</button></div>`;
        app.querySelector('#quiz-start').onclick=async event=>{event.target.disabled=true;try{const response=await ctx.api('start',{id:ctx.id});answers=response.answers||{};loaded.response=response;render();}catch(error){ctx.message(error.message);event.target.disabled=false;}};
        return stop;
    }
    play.preview=function(d,ctx){const dialog=document.createElement('dialog');dialog.className='quiz-preview';dialog.innerHTML='<button class="btn btn-outline-secondary mb-3" id="close-preview">Close preview</button><div class="quiz-workspace" id="preview-app"></div>';document.body.append(dialog);const stop=play({...ctx,app:dialog.querySelector('#preview-app')},{definition:structuredClone(d)},true);dialog.querySelector('#close-preview').onclick=()=>dialog.close();dialog.addEventListener('close',()=>{stop?.();dialog.remove();});dialog.showModal();};
    window.QuizPlayer=play;
})();
