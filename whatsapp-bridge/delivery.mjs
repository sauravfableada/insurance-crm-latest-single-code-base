import { createHash } from 'node:crypto';

export function failure(message,code=422) { return Object.assign(new Error(message),{httpCode:code}); }

export async function send(entry,body) {
    if (!/^[1-9]\d{7,14}$/.test(body.phone || '') || typeof body.message!=='string' || !body.message.trim() || body.message.length>4000 || !/^[0-9a-f-]{36}$/i.test(body.request_id || '') || !/^\d+$/.test(body.policy_id || '')) throw failure('Invalid reminder request.');
    const digest=createHash('sha256').update(JSON.stringify([body.phone,body.message,body.policy_id])).digest('hex');
    const previous=entry.store.data.messages[body.request_id];
    if (previous) {
        if (previous.digest!==digest) throw failure('This request ID was already used for a different message.',409);
        if (previous.state==='sent') return {status:'sent',message:'Reminder already submitted to WhatsApp.',message_id:previous.id};
        throw failure('This send is processing or its delivery is uncertain. Check WhatsApp before sending another reminder.',409);
    }
    if (entry.status!=='open') throw failure('Connect WhatsApp first.',409);
    const [recipient]=await entry.socket.onWhatsApp(body.phone);
    if (!recipient?.exists) throw failure('This number is not registered on WhatsApp.');
    entry.store.data.messages[body.request_id]={state:'pending',digest,time:Date.now()};
    await entry.store.save();
    // Never retry sends automatically. A lost acknowledgement may still mean delivered.
    try {
        const sent=await entry.socket.sendMessage(recipient.jid,{text:body.message});
        entry.store.data.messages[body.request_id]={state:'sent',digest,id:sent?.key?.id,time:Date.now()};
        await entry.store.save();
        return {status:'sent',message:'Reminder submitted to WhatsApp.',message_id:sent?.key?.id};
    } catch { throw failure('Delivery could not be confirmed. Check WhatsApp before retrying.',409); }
}
