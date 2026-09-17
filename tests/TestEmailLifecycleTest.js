'use strict';
const fs=require('fs');
const vm=require('vm');
function assert(condition,message){if(!condition)throw new Error(message);}
const helperWindow={_ccLiveLoaded:true};
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/js/live-view.js','utf8'),{window:helperWindow});
const lifecycle=helperWindow.CCTestEmailLifecycle.create();
let feedback='Prior success';
function reset(recipient){lifecycle.reset(recipient);feedback='';}
function complete(request,recipient,message){if(lifecycle.accepts(request,recipient))feedback=message;}
reset('first@example.com');
assert(feedback==='','Opening Live Settings clears prior Test-email feedback');
const first=lifecycle.begin('first@example.com');
reset('second@example.com');
assert(feedback===''&&!lifecycle.accepts(first,'second@example.com'),'Changing the recipient clears feedback and invalidates its in-flight request');
complete(first,'second@example.com','Wrong recipient success');
assert(feedback==='','An obsolete response cannot appear beside a different recipient');
const older=lifecycle.begin('second@example.com');
const newer=lifecycle.begin('second@example.com');
complete(older,'second@example.com','Old success');
assert(feedback==='','An older Test request cannot overwrite a newer request');
complete(newer,'second@example.com','Current success');
assert(feedback==='Current success','The current valid Test request displays success');
const failure=lifecycle.begin('second@example.com');
complete(failure,'second@example.com','Current failure');
assert(feedback==='Current failure','The current valid Test request displays failure');
reset('second@example.com');
assert(feedback==='','Reopening settings after a completed request clears feedback');
console.log('Test email lifecycle tests passed');