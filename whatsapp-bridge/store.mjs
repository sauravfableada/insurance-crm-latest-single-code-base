import { mkdir, readFile, writeFile, rename } from 'node:fs/promises';
import { join } from 'node:path';
import { randomBytes, createCipheriv, createDecipheriv } from 'node:crypto';

// A single encrypted, atomic snapshot per CRM user. Signal keys and credentials
// share the same serial write queue; no plaintext session files are created.
export async function encryptedStore(directory, id, key, replacer, reviver) {
    await mkdir(directory, { recursive: true, mode: 0o700 });
    const path=join(directory, `${id}.enc`);
    let data={ creds:null, keys:{}, messages:{} };
    try {
        const file=await readFile(path);
        const decipher=createDecipheriv('aes-256-gcm', key, file.subarray(0,12));
        decipher.setAuthTag(file.subarray(12,28));
        data=JSON.parse(Buffer.concat([decipher.update(file.subarray(28)),decipher.final()]).toString(),reviver);
    } catch (error) { if (error.code !== 'ENOENT') throw error; }
    let queue=Promise.resolve();
    return {
        data,
        save() {
            const snapshot=JSON.stringify(data,replacer);
            const operation=queue.then(async()=>{
                const iv=randomBytes(12), cipher=createCipheriv('aes-256-gcm',key,iv);
                const ciphertext=Buffer.concat([cipher.update(snapshot),cipher.final()]);
                const temp=`${path}.${randomBytes(8).toString('hex')}.tmp`;
                await writeFile(temp,Buffer.concat([iv,cipher.getAuthTag(),ciphertext]),{mode:0o600});
                await rename(temp,path);
            });
            queue=operation.catch(()=>{});
            return operation;
        }
    };
}
