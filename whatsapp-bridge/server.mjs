import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { resolve } from 'node:path';
import makeWASocket, { BufferJSON, initAuthCreds, proto, DisconnectReason, makeCacheableSignalKeyStore } from '@whiskeysockets/baileys';
import QRCode from 'qrcode';
import pino from 'pino';
import { encryptedStore } from './store.mjs';
import { send, failure } from './delivery.mjs';

const token=process.env.BRIDGE_TOKEN || '';
const key=Buffer.from(process.env.BRIDGE_ENCRYPTION_KEY || '', 'hex');
if (token.length<32 || key.length!==32) throw new Error('Set BRIDGE_TOKEN (32+ characters) and BRIDGE_ENCRYPTION_KEY (64 hex characters).');
const directory=resolve(process.env.BRIDGE_DATA_DIR || './private-data');
const sessions=new Map(), opening=new Map();
const logger=pino({level:'silent'});
const states=new Set(['open','connecting','qr','reconnecting']);

async function session(id) {
    if (sessions.has(id)) return sessions.get(id);
    if (opening.has(id)) return opening.get(id);
    const pending=(async()=>{
        const store=await encryptedStore(directory,id,key,BufferJSON.replacer,BufferJSON.reviver);
        const entry={store,status:'disconnected',socket:null,qr:null,generation:0,retries:0,timer:null,connecting:null,operations:Promise.resolve(),authQueue:Promise.resolve()};
        sessions.set(id,entry);
        return entry;
    })();
    opening.set(id,pending);
    try { return await pending; } finally { opening.delete(id); }
}

async function connect(entry) {
    if (entry.connecting) return entry.connecting;
    if (entry.socket && states.has(entry.status)) return;
    const task=start(entry);
    entry.connecting=task;
    try { await task; } finally { entry.connecting=null; }
}
async function start(entry) {
    clearTimeout(entry.timer);
    const generation=++entry.generation;
    entry.socket?.end(new Error('Reconnecting'));
    if (!entry.store.data.creds) { entry.store.data.creds=initAuthCreds(); await entry.store.save(); }
    entry.status='connecting'; entry.qr=null;
    const auth={
        creds:entry.store.data.creds,
        keys:{
            async get(type,ids) {
                await entry.authQueue;
                const found={};
                for (const id of ids) {
                    let value=entry.store.data.keys[type]?.[id];
                    if (value && type==='app-state-sync-key') value=proto.Message.AppStateSyncKeyData.fromObject(value);
                    if (value) found[id]=value;
                }
                return found;
            },
            async set(values) {
                const task=entry.authQueue.then(async()=>{
                    if (generation!==entry.generation) return;
                    for (const [type,items] of Object.entries(values)) {
                        entry.store.data.keys[type] ||= {};
                        for (const [id,value] of Object.entries(items)) {
                            if (value) entry.store.data.keys[type][id]=value;
                            else delete entry.store.data.keys[type][id];
                        }
                    }
                    await entry.store.save();
                });
                entry.authQueue=task.catch(()=>{}); return task;
            }
        }
    };
    const socket=makeWASocket({auth:{creds:auth.creds,keys:makeCacheableSignalKeyStore(auth.keys,logger)},logger,markOnlineOnConnect:false,syncFullHistory:false});
    entry.socket=socket;
    socket.ev.on('creds.update', async update=>{
        if (generation!==entry.generation) return;
        Object.assign(entry.store.data.creds,update);
        try { await entry.store.save(); } catch { entry.status='storage_error'; socket.end(new Error('Session storage unavailable')); }
    });
    socket.ev.on('connection.update', async update=>{
        if (generation!==entry.generation) return;
        try {
            if (update.qr) {
                const image=await QRCode.toDataURL(update.qr,{width:280,margin:2});
                if (generation!==entry.generation) return;
                entry.qr=image; entry.status='qr';
            }
            if (update.connection==='open') { entry.status='open';entry.qr=null;entry.retries=0; }
            if (update.connection==='close') {
                ++entry.generation;
                entry.socket=null;entry.qr=null;
                const code=update.lastDisconnect?.error?.output?.statusCode;
                if (code===DisconnectReason.loggedOut) {
                    entry.status='disconnected';await entry.authQueue;entry.store.data.creds=null;entry.store.data.keys={};await entry.store.save();
                } else if (entry.status!=='storage_error' && ++entry.retries<=8) {
                    entry.status='reconnecting';entry.timer=setTimeout(()=>connect(entry).catch(()=>{entry.status='error';}),Math.min(30000,1000*2**(entry.retries-1)));
                } else if (entry.status!=='storage_error') entry.status='error';
            }
        } catch { entry.status='error'; }
    });
}

async function disconnect(entry) {
    clearTimeout(entry.timer); ++entry.generation;
    const socket=entry.socket;entry.socket=null;entry.qr=null;entry.status='disconnected';
    try { if (socket) await socket.logout(); } catch { socket?.end(new Error('Disconnected by CRM user')); }
    await entry.authQueue;
    entry.store.data.creds=null;entry.store.data.keys={};await entry.store.save();
}
function status(entry) {
    return {status:entry.status,connected:entry.status==='open',qr:entry.qr,account:entry.status==='open' ? (entry.socket?.user?.id || '').split(':')[0].split('@')[0] : null};
}
const server=http.createServer(async(req,res)=>{
    res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
    const reply=(code,value)=>{res.writeHead(code);res.end(JSON.stringify(value));};
    const supplied=Buffer.from((req.headers.authorization || '').replace(/^Bearer /,''));
    if (supplied.length!==Buffer.byteLength(token) || !timingSafeEqual(supplied,Buffer.from(token))) return reply(401,{message:'Unauthorized'});
    const match=/^\/sessions\/([1-9]\d*)\/(status|connect|disconnect|send)$/.exec(req.url);
    if (req.method!=='POST' || !match) return reply(404,{message:'Not found'});
    try {
        let raw='';for await (const chunk of req) { raw+=chunk;if (Buffer.byteLength(raw)>24000) throw failure('Request too large.',413); }
        const body=raw ? JSON.parse(raw) : {};
        const entry=await session(match[1]), action=match[2];
        const task=entry.operations.then(async()=>{
            if (action==='connect') { entry.retries=0;if (entry.status==='qr') entry.status='disconnected';await connect(entry); }
            if (action==='status' && entry.store.data.creds?.registered && !entry.socket && entry.status==='disconnected') await connect(entry);
            if (action==='disconnect') await disconnect(entry);
            if (action==='send') return send(entry,body);
            return status(entry);
        });
        entry.operations=task.catch(()=>{});
        return reply(200,await task);
    } catch(error) { return reply(error.httpCode || 503,{message:error.httpCode ? error.message : 'Bridge could not complete the request. Check the service and session storage.'}); }
});
// Private loopback only. Browsers use Laravel's authenticated/CSRF-protected proxy.
server.listen(Number(process.env.BRIDGE_PORT || 3210),'127.0.0.1',()=>console.log('WhatsApp bridge listening on loopback.'));
async function shutdown() { server.close();for (const entry of sessions.values()) {clearTimeout(entry.timer);++entry.generation;entry.socket?.end(new Error('Bridge stopped'));await entry.store.save();}process.exit(0); }
process.on('SIGTERM',shutdown);process.on('SIGINT',shutdown);
