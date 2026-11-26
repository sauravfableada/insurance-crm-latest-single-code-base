import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, writeFile, readdir, unlink, rmdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';
import { BufferJSON } from '@whiskeysockets/baileys';
import { encryptedStore } from '../store.mjs';

test('auth snapshots preserve Buffer keys and serialize writes without storing plaintext',async()=>{
    const directory=await mkdtemp(join(tmpdir(),'wa-store-')),key=randomBytes(32);
    try {
        const store=await encryptedStore(directory,'10',key,BufferJSON.replacer,BufferJSON.reviver);
        store.data.creds={secret:'private-session-value',key:Buffer.from('key')};
        const first=store.save();store.data.keys={signal:{key:Buffer.from('signal-key')}};const second=store.save();await Promise.all([first,second]);
        assert.equal((await readFile(join(directory,'10.enc'))).includes(Buffer.from('private-session-value')),false);
        const loaded=await encryptedStore(directory,'10',key,BufferJSON.replacer,BufferJSON.reviver);
        assert.equal(loaded.data.creds.secret,'private-session-value');assert.deepEqual(loaded.data.keys.signal.key,Buffer.from('signal-key'));
        assert.equal((await encryptedStore(directory,'11',key)).data.creds,null);
        await assert.rejects(()=>encryptedStore(directory,'10',randomBytes(32)));
        const bytes=await readFile(join(directory,'10.enc'));bytes[30]^=1;await writeFile(join(directory,'10.enc'),bytes);
        await assert.rejects(()=>encryptedStore(directory,'10',key));
    } finally {for (const file of await readdir(directory)) await unlink(join(directory,file));await rmdir(directory);}
});
