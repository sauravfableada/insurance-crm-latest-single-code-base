import { test } from 'node:test';
import assert from 'node:assert/strict';
import { send } from '../delivery.mjs';

const body={phone:'919228262343',message:'Reviewed renewal reminder',request_id:'123e4567-e89b-42d3-a456-426614174000',policy_id:'15'};
function entry() {
    const item={status:'open',calls:0,snapshots:[],store:{data:{messages:{}}}};
    item.store.save=async()=>item.snapshots.push(structuredClone(item.store.data));
    item.socket={onWhatsApp:async()=>[{exists:true,jid:body.phone+'@s.whatsapp.net'}],sendMessage:async(jid,value)=>{item.calls++;assert.equal(jid,body.phone+'@s.whatsapp.net');assert.equal(value.text,body.message);return {key:{id:'sent-id'}};}};
    return item;
}
test('explicit send is journalled first and repeat request never sends twice',async()=>{
    const item=entry();assert.equal((await send(item,body)).status,'sent');
    assert.equal(item.snapshots[0].messages[body.request_id].state,'pending');
    assert.equal((await send(item,body)).message_id,'sent-id');assert.equal(item.calls,1);
    await assert.rejects(()=>send(item,{...body,message:'different'}),{httpCode:409});assert.equal(item.calls,1);
});
test('uncertain send is not retried automatically',async()=>{
    const item=entry();item.socket.sendMessage=async()=>{item.calls++;throw new Error('Lost acknowledgement');};
    await assert.rejects(()=>send(item,body),{httpCode:409});await assert.rejects(()=>send(item,body),{httpCode:409});assert.equal(item.calls,1);
});
test('disconnected, invalid and unregistered recipient requests do not send',async()=>{
    const item=entry();item.status='disconnected';await assert.rejects(()=>send(item,body),{httpCode:409});
    item.status='open';await assert.rejects(()=>send(item,{...body,phone:'bad'}),{httpCode:422});
    item.socket.onWhatsApp=async()=>[];await assert.rejects(()=>send(item,body),{httpCode:422});assert.equal(item.calls,0);
});
test('failed journal write prevents outbound send',async()=>{
    const item=entry();item.store.save=async()=>{throw new Error('Disk full');};
    await assert.rejects(()=>send(item,body));assert.equal(item.calls,0);
});
