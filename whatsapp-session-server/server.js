const express = require('express');
const http = require('http');
const cors = require('cors');
const qrcode = require('qrcode');
const path = require('path');
const fs = require('fs');
const pino = require('pino');
const {
    default: makeWASocket,
    DisconnectReason,
    useMultiFileAuthState,
    fetchLatestBaileysVersion,
    getBinaryNodeChild,
    getBinaryNodeChildren
} = require('@whiskeysockets/baileys');

/**
 * Sync persisted LID <-> Phone Number mappings from Baileys auth keys files
 */
function syncLidMappingsFromDisk(sessionData, sessionAuthDir) {
    try {
        if (!fs.existsSync(sessionAuthDir)) return;
        const files = fs.readdirSync(sessionAuthDir);
        for (const file of files) {
            if (file.startsWith('lid-mapping-') && file.endsWith('.json')) {
                try {
                    const content = JSON.parse(fs.readFileSync(path.join(sessionAuthDir, file), 'utf8'));
                    if (content && typeof content === 'string') {
                        const cleanVal = content.split('@')[0].split(':')[0].replace(/\D/g, '');
                        const keyPart = file.replace('lid-mapping-', '').replace('.json', '');
                        if (keyPart.endsWith('_reverse')) {
                            const lidDigits = keyPart.replace('_reverse', '').replace(/\D/g, '');
                            if (lidDigits && cleanVal && lidDigits !== cleanVal && cleanVal.length >= 7 && cleanVal.length <= 15) {
                                sessionData.lidMap.set(`${lidDigits}@lid`, cleanVal);
                                sessionData.lidMap.set(lidDigits, cleanVal);
                            }
                        } else {
                            const pnDigits = keyPart.replace(/\D/g, '');
                            const lidDigits = cleanVal;
                            if (pnDigits && lidDigits && pnDigits !== lidDigits && pnDigits.length >= 7 && pnDigits.length <= 15) {
                                sessionData.lidMap.set(`${lidDigits}@lid`, pnDigits);
                                sessionData.lidMap.set(lidDigits, pnDigits);
                            }
                        }
                    }
                } catch (e) {}
            }
        }
    } catch (e) {}
}

/**
 * Persist contacts and lid mappings for a session to disk
 */
function persistSessionMaps(sessionData) {
    if (!sessionData || !sessionData.authDir) return;
    try {
        const contactsStoreFile = path.join(sessionData.authDir, 'contacts_store.json');
        const lidMapFile = path.join(sessionData.authDir, 'lid_map.json');
        const cObj = Object.fromEntries(sessionData.contacts || []);
        const lObj = Object.fromEntries(sessionData.lidMap || []);
        fs.writeFileSync(contactsStoreFile, JSON.stringify(cObj, null, 2));
        fs.writeFileSync(lidMapFile, JSON.stringify(lObj, null, 2));
    } catch (e) {
        console.error('[Bridge] Error persisting session maps:', e.message);
    }
}

/**
 * Set a contact entry in sessionData.contacts
 */
function setSessionContactEntry(sessionData, key, phone, name) {
    if (!sessionData || !sessionData.contacts || !key) return;
    const prev = sessionData.contacts.get(key) || {};
    const mergedPhone = phone || prev.phone || null;
    const mergedName = name || prev.name || null;
    if (mergedPhone || mergedName) {
        sessionData.contacts.set(key, {
            phone: mergedPhone,
            name: mergedName
        });
    }
}

/**
 * Record a contact with all identifier variants
 */
function recordSessionContact(sessionData, id, jid, lid, phone, name) {
    if (!sessionData) return;
    let cleanPhone = phone ? String(phone).replace(/\D/g, '') : null;
    if (!cleanPhone && jid && jid.endsWith('@s.whatsapp.net')) {
        cleanPhone = jid.split('@')[0].split(':')[0];
    }
    if (!cleanPhone && id && id.endsWith('@s.whatsapp.net')) {
        cleanPhone = id.split('@')[0].split(':')[0];
    }

    const cleanName = (name && typeof name === 'string' && name.trim()) ? name.trim() : null;

    if (id) setSessionContactEntry(sessionData, id, cleanPhone, cleanName);
    if (jid) setSessionContactEntry(sessionData, jid, cleanPhone, cleanName);
    if (lid) setSessionContactEntry(sessionData, lid, cleanPhone, cleanName);

    if (cleanPhone) {
        setSessionContactEntry(sessionData, cleanPhone, cleanPhone, cleanName);
        setSessionContactEntry(sessionData, `${cleanPhone}@s.whatsapp.net`, cleanPhone, cleanName);
    }

    if (lid) {
        const cleanLid = lid.replace(/\D/g, '');
        setSessionContactEntry(sessionData, cleanLid, cleanPhone, cleanName);
        setSessionContactEntry(sessionData, `${cleanLid}@lid`, cleanPhone, cleanName);
        if (cleanPhone && cleanLid && cleanLid !== cleanPhone && cleanPhone.length >= 7 && cleanPhone.length <= 15) {
            sessionData.lidMap.set(lid, cleanPhone);
            sessionData.lidMap.set(`${cleanLid}@lid`, cleanPhone);
            sessionData.lidMap.set(cleanLid, cleanPhone);
        }
    }

    if (id && id.endsWith('@lid')) {
        const cleanId = id.replace(/\D/g, '');
        setSessionContactEntry(sessionData, cleanId, cleanPhone, cleanName);
        setSessionContactEntry(sessionData, `${cleanId}@lid`, cleanPhone, cleanName);
        if (cleanPhone && cleanId && cleanId !== cleanPhone && cleanPhone.length >= 7 && cleanPhone.length <= 15) {
            sessionData.lidMap.set(id, cleanPhone);
            sessionData.lidMap.set(cleanId, cleanPhone);
        }
    }
}

const app = express();
const PORT = process.env.WHATSAPP_SESSION_PORT || 3001;
const SECRET_KEY = process.env.WHATSAPP_SESSION_SECRET || 'wa_session_secret_key_2026';

process.on('uncaughtException', (err) => {
    console.error('[Bridge] Uncaught Exception:', err.message || err);
});
process.on('unhandledRejection', (reason) => {
    console.error('[Bridge] Unhandled Rejection:', reason?.message || reason);
});

app.use(cors());
app.use(express.json());

// In-memory sessions store
// Map<string, { sock, status, qr, qrRaw, phone, name, lastActive, authDir }>
const sessions = new Map();

const SESSIONS_DIR = path.join(__dirname, 'auth_sessions');
if (!fs.existsSync(SESSIONS_DIR)) {
    fs.mkdirSync(SESSIONS_DIR, { recursive: true });
}

// Health check endpoint (open for local monitoring and probes)
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        uptime: process.uptime(),
        active_sessions: sessions.size,
        timestamp: new Date().toISOString()
    });
});

// Simple authentication middleware for API routes
const authMiddleware = (req, res, next) => {
    const clientSecret = req.headers['x-session-secret'] || req.query.secret;
    if (SECRET_KEY && clientSecret !== SECRET_KEY) {
        return res.status(401).json({ status: false, message: 'Unauthorized session request' });
    }
    next();
};

app.use(authMiddleware);

/**
 * Initialize or start a WhatsApp session
 */
async function startSession(sessionId) {
    if (sessions.has(sessionId)) {
        const existing = sessions.get(sessionId);
        if (existing.status === 'connected' && existing.sock) {
            return existing;
        }
    }

    const sessionAuthDir = path.join(SESSIONS_DIR, sessionId);
    if (!fs.existsSync(sessionAuthDir)) {
        fs.mkdirSync(sessionAuthDir, { recursive: true });
    }

    const { state, saveCreds } = await useMultiFileAuthState(sessionAuthDir);
    const { version } = await fetchLatestBaileysVersion();

    const sessionData = {
        sock: null,
        status: 'initializing',
        qr: null,
        qrRaw: null,
        phone: null,
        name: null,
        lastActive: new Date(),
        authDir: sessionAuthDir,
        contacts: new Map(), // jid/lid -> { phone, name }
        lidMap: new Map()    // lid -> phone
    };
    sessions.set(sessionId, sessionData);

    const sock = makeWASocket({
        version,
        auth: state,
        printQRInTerminal: false,
        logger: pino({ level: 'silent' }),
        browser: ['Chrome (Windows)', 'Desktop', '120.0.0'],
        syncFullHistory: true
    });

    sessionData.sock = sock;

    const contactsStoreFile = path.join(sessionAuthDir, 'contacts_store.json');
    const lidMapFile = path.join(sessionAuthDir, 'lid_map.json');

    // Load persisted maps if they exist
    if (fs.existsSync(contactsStoreFile)) {
        try {
            const raw = JSON.parse(fs.readFileSync(contactsStoreFile, 'utf8'));
            for (const [k, v] of Object.entries(raw)) {
                if (k && v) sessionData.contacts.set(k, v);
            }
        } catch (e) {}
    }
    if (fs.existsSync(lidMapFile)) {
        try {
            const raw = JSON.parse(fs.readFileSync(lidMapFile, 'utf8'));
            for (const [k, v] of Object.entries(raw)) {
                if (k && v) sessionData.lidMap.set(k, v);
            }
        } catch (e) {}
    }

    // Also load any raw Baileys auth keys mappings
    syncLidMappingsFromDisk(sessionData, sessionAuthDir);

    const persistMaps = () => persistSessionMaps(sessionData);
    const setContactEntry = (key, phone, name) => setSessionContactEntry(sessionData, key, phone, name);
    const recordContact = (id, jid, lid, phone, name) => recordSessionContact(sessionData, id, jid, lid, phone, name);

    sessionData.persistMaps = persistMaps;
    sessionData.setContactEntry = setContactEntry;
    sessionData.recordContact = recordContact;

    // Contact and History Sync Listeners
    sock.ev.on('messaging-history.set', ({ contacts, messages, lidPnMappings }) => {
        if (Array.isArray(lidPnMappings)) {
            for (const m of lidPnMappings) {
                if (m && m.lid && m.pn) {
                    const cleanPhone = String(m.pn).split('@')[0].split(':')[0].replace(/\D/g, '');
                    const cleanLid = String(m.lid).split('@')[0].split(':')[0].replace(/\D/g, '');
                    if (cleanPhone && cleanLid) {
                        sessionData.lidMap.set(m.lid, cleanPhone);
                        sessionData.lidMap.set(`${cleanLid}@lid`, cleanPhone);
                        sessionData.lidMap.set(cleanLid, cleanPhone);
                        setContactEntry(m.lid, cleanPhone, null);
                        setContactEntry(`${cleanLid}@lid`, cleanPhone, null);
                        setContactEntry(cleanLid, cleanPhone, null);
                    }
                }
            }
        }
        if (Array.isArray(contacts)) {
            for (const c of contacts) {
                if (c) {
                    recordContact(c.id, c.jid, c.lid, c.phoneNumber || c.phone, c.name || c.notify || c.verifiedName);
                }
            }
        }
        if (Array.isArray(messages)) {
            for (const m of messages) {
                const sender = m.key?.participant || m.key?.remoteJid;
                const alt = m.senderPn || m.key?.participantPn || m.key?.participantAlt || m.key?.remoteJidAlt;
                if (sender && alt) {
                    recordContact(sender, alt, sender.endsWith('@lid') ? sender : null, alt, m.pushName);
                }
            }
        }
        persistMaps();
    });

    // Listen for Baileys LID <-> Phone Number mapping updates
    sock.ev.on('lid-mapping.update', (update) => {
        try {
            const mappings = Array.isArray(update) ? update : [update];
            for (const m of mappings) {
                if (!m) continue;
                const lid = m.lid ? String(m.lid).trim() : null;
                const rawPn = m.pn ? String(m.pn).trim() : null;
                if (!lid || !rawPn) continue;

                const cleanPhone = rawPn.split('@')[0].split(':')[0].replace(/\D/g, '');
                if (!cleanPhone) continue;

                const cleanLid = lid.split('@')[0].split(':')[0].replace(/\D/g, '');

                sessionData.lidMap.set(lid, cleanPhone);
                sessionData.lidMap.set(`${cleanLid}@lid`, cleanPhone);
                sessionData.lidMap.set(cleanLid, cleanPhone);

                setContactEntry(lid, cleanPhone, null);
                setContactEntry(`${cleanLid}@lid`, cleanPhone, null);
                setContactEntry(cleanLid, cleanPhone, null);
                setContactEntry(cleanPhone, cleanPhone, null);
                setContactEntry(`${cleanPhone}@s.whatsapp.net`, cleanPhone, null);
            }
            persistMaps();
        } catch (err) {
            console.error(`[Bridge] Error in lid-mapping.update for ${sessionId}:`, err);
        }
    });

    sock.ev.on('contacts.upsert', (contacts) => {
        if (Array.isArray(contacts)) {
            for (const c of contacts) {
                if (c) {
                    recordContact(c.id, c.jid, c.lid, c.phoneNumber || c.phone, c.name || c.notify || c.verifiedName);
                }
            }
            persistMaps();
        }
    });

    sock.ev.on('contacts.update', (updates) => {
        if (Array.isArray(updates)) {
            for (const u of updates) {
                if (u && u.id && sessionData.contacts.has(u.id)) {
                    const prev = sessionData.contacts.get(u.id);
                    sessionData.contacts.set(u.id, {
                        ...prev,
                        name: u.name || u.notify || prev.name
                    });
                }
            }
            persistMaps();
        }
    });

    // Capture user pushNames and alternate phone numbers from group and chat messages
    sock.ev.on('messages.upsert', ({ messages }) => {
        if (Array.isArray(messages)) {
            for (const m of messages) {
                const sender = m.key?.participant || m.key?.remoteJid;
                const pushName = m.pushName;
                const alt = m.senderPn || m.key?.participantAlt || m.key?.participantPn || m.key?.remoteJidAlt;
                if (sender && (alt || pushName)) {
                    recordContact(sender, alt, sender.endsWith('@lid') ? sender : null, alt, pushName);
                }
            }
            persistMaps();
        }
    });

    // Capture saved chat names (individual and group chats)
    sock.ev.on('chats.set', ({ chats }) => {
        if (Array.isArray(chats)) {
            for (const chat of chats) {
                if (chat && chat.id && chat.name) {
                    recordContact(chat.id, chat.id, null, null, chat.name);
                }
            }
            persistMaps();
        }
    });

    sock.ev.on('chats.upsert', (chats) => {
        if (Array.isArray(chats)) {
            for (const chat of chats) {
                if (chat && chat.id && chat.name) {
                    recordContact(chat.id, chat.id, null, null, chat.name);
                }
            }
            persistMaps();
        }
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            sessionData.status = 'qr_ready';
            sessionData.qrRaw = qr;
            try {
                sessionData.qr = await qrcode.toDataURL(qr, { margin: 2, scale: 6 });
            } catch (err) {
                console.error(`QR generation error for ${sessionId}:`, err);
            }
        }

        if (connection === 'open') {
            sessionData.status = 'connected';
            sessionData.qr = null;
            sessionData.qrRaw = null;
            const userJid = sock.user?.id || '';
            sessionData.phone = userJid.split(':')[0] || userJid.split('@')[0];
            sessionData.name = sock.user?.name || 'WhatsApp User';
            sessionData.lastActive = new Date();

            // Store own LID mapping immediately
            if (sock.user?.lid) {
                recordContact(sock.user.id, sock.user.id, sock.user.lid, sessionData.phone, sessionData.name);
            }
            persistMaps();
            console.log(`[WhatsApp Session] ${sessionId} connected successfully (${sessionData.phone})`);
        }

        if (connection === 'close') {
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            console.log(`[WhatsApp Session] ${sessionId} closed. Reconnect: ${shouldReconnect}, Code: ${statusCode}`);

            if (statusCode === DisconnectReason.loggedOut) {
                sessionData.status = 'disconnected';
                sessionData.qr = null;
                sessionData.sock = null;
                try {
                    fs.rmSync(sessionAuthDir, { recursive: true, force: true });
                } catch (e) {}
            } else if (shouldReconnect) {
                sessionData.status = 'reconnecting';
                setTimeout(() => startSession(sessionId), 3000);
            } else {
                sessionData.status = 'disconnected';
                sessionData.sock = null;
            }
        }
    });

    return sessionData;
}

// 1. Initialize or connect session
app.post('/sessions/init', async (req, res) => {
    try {
        const { session_id } = req.body;
        if (!session_id) {
            return res.status(400).json({ status: false, message: 'session_id is required' });
        }

        const session = await startSession(session_id);

        // Wait up to 6 seconds for QR code or connected status so the first response returns the QR code
        if (session.status === 'initializing' && !session.qr) {
            await new Promise((resolve) => {
                let checks = 0;
                const interval = setInterval(() => {
                    checks++;
                    if (session.qr || session.status === 'connected' || checks >= 24) {
                        clearInterval(interval);
                        resolve();
                    }
                }, 250);
            });
        }

        res.json({
            status: session.status,
            session_id,
            qr: session.qr,
            qr_raw: session.qrRaw,
            phone: session.phone,
            name: session.name
        });
    } catch (error) {
        console.error('Error in /sessions/init:', error);
        res.status(500).json({ status: 'error', message: error.message });
    }
});

// 2. Get session status & QR
app.get('/sessions/:sessionId/status', async (req, res) => {
    const { sessionId } = req.params;
    let session = sessions.get(sessionId);

    // If session not in memory but directory exists on disk, restore it
    if (!session) {
        const sessionAuthDir = path.join(SESSIONS_DIR, sessionId);
        if (fs.existsSync(sessionAuthDir) && fs.existsSync(path.join(sessionAuthDir, 'creds.json'))) {
            try {
                session = await startSession(sessionId);
            } catch (e) {
                return res.json({ status: 'disconnected', is_connected: false });
            }
        } else {
            return res.json({ status: 'disconnected', is_connected: false });
        }
    }

    res.json({
        status: session.status,
        session_id: sessionId,
        phone: session.phone,
        name: session.name,
        qr: session.qr,
        qr_raw: session.qrRaw,
        is_connected: session.status === 'connected'
    });
});

// 3. Disconnect session
app.delete('/sessions/:sessionId', async (req, res) => {
    const { sessionId } = req.params;
    const session = sessions.get(sessionId);

    if (session && session.sock) {
        try {
            await session.sock.logout();
        } catch (e) {}
    }

    const sessionAuthDir = path.join(SESSIONS_DIR, sessionId);
    try {
        fs.rmSync(sessionAuthDir, { recursive: true, force: true });
    } catch (e) {}

    sessions.delete(sessionId);

    res.json({ status: true, message: 'Session disconnected and deleted' });
});

// 3b. Send text message via connected session
app.post('/sessions/:sessionId/send-message', async (req, res) => {
    const { sessionId } = req.params;
    let session = sessions.get(sessionId);

    // If session not in memory but directory exists on disk, restore it
    if (!session) {
        const sessionAuthDir = path.join(SESSIONS_DIR, sessionId);
        if (fs.existsSync(sessionAuthDir) && fs.existsSync(path.join(sessionAuthDir, 'creds.json'))) {
            try {
                session = await startSession(sessionId);
            } catch (e) {
                return res.status(400).json({ status: false, message: 'Session could not be restored: ' + e.message });
            }
        } else {
            return res.status(400).json({ status: false, message: 'Session not found: ' + sessionId });
        }
    }

    if (!session || session.status !== 'connected' || !session.sock) {
        return res.status(400).json({
            status: false,
            message: 'WhatsApp session is not connected. Current status: ' + (session ? session.status : 'not_found')
        });
    }

    const to = req.body.to || req.body.phone || req.body.number || req.body.recipient;
    const text = req.body.text || req.body.message || req.body.body;

    if (!to) {
        return res.status(400).json({ status: false, message: 'Recipient phone number ("to") is required.' });
    }
    if (!text) {
        return res.status(400).json({ status: false, message: 'Message text ("text") is required.' });
    }

    let cleanTo = String(to).replace(/\D/g, '');
    if (cleanTo.length === 10 && /^[6-9]/.test(cleanTo)) {
        cleanTo = '91' + cleanTo;
    }

    let targetJid = `${cleanTo}@s.whatsapp.net`;
    try {
        if (typeof session.sock.onWhatsApp === 'function') {
            const checkRes = await session.sock.onWhatsApp(cleanTo);
            if (Array.isArray(checkRes) && checkRes.length > 0) {
                const found = checkRes.find(c => c && c.exists) || checkRes[0];
                if (found && found.jid) {
                    targetJid = found.jid;
                }
            }
        }
    } catch (checkErr) {
        console.warn(`[Bridge] onWhatsApp check warning for ${cleanTo}:`, checkErr.message);
    }

    // Socket warming: set typing presence like in inventory-billing
    try {
        if (typeof session.sock.sendPresenceUpdate === 'function') {
            await session.sock.sendPresenceUpdate('composing', targetJid).catch(() => {});
        }
    } catch (presenceErr) {}

    try {
        const sentMsg = await session.sock.sendMessage(targetJid, { text: String(text) });
        return res.json({
            status: true,
            success: true,
            message_id: sentMsg?.key?.id || null,
            to: cleanTo,
            jid: targetJid,
            from: session.phone || null,
            timestamp: new Date().toISOString()
        });
    } catch (err) {
        console.error(`[Bridge] Error sending message on session ${sessionId} to ${cleanTo} (${targetJid}):`, err.message);
        return res.status(500).json({
            status: false,
            message: 'Failed to send WhatsApp message: ' + (err.message || String(err)),
            error: err.stack || null
        });
    }
});

// 3c. Send document (PDF invoice) via connected session
app.post('/sessions/:sessionId/send-document', async (req, res) => {
    const { sessionId } = req.params;
    let session = sessions.get(sessionId);

    if (!session) {
        const sessionAuthDir = path.join(SESSIONS_DIR, sessionId);
        if (fs.existsSync(sessionAuthDir) && fs.existsSync(path.join(sessionAuthDir, 'creds.json'))) {
            try {
                session = await startSession(sessionId);
            } catch (e) {
                return res.status(400).json({ status: false, message: 'Session could not be restored: ' + e.message });
            }
        } else {
            return res.status(400).json({ status: false, message: 'Session not found: ' + sessionId });
        }
    }

    if (!session || session.status !== 'connected' || !session.sock) {
        return res.status(400).json({
            status: false,
            message: 'WhatsApp session is not connected. Current status: ' + (session ? session.status : 'not_found')
        });
    }

    const to = req.body.to || req.body.phone || req.body.number || req.body.recipient;
    const caption = req.body.caption || req.body.text || req.body.message || '';
    const fileName = String(req.body.fileName || req.body.filename || 'document.pdf').replace(/[^\w.\-]+/g, '_') || 'document.pdf';
    const mimetype = req.body.mimetype || req.body.mime_type || 'application/pdf';
    let mediaBase64 = req.body.media || req.body.document || req.body.file || req.body.base64 || '';

    if (!to) {
        return res.status(400).json({ status: false, message: 'Recipient phone number ("to") is required.' });
    }
    if (!mediaBase64) {
        return res.status(400).json({ status: false, message: 'Document media (base64) is required.' });
    }

    if (typeof mediaBase64 === 'string' && mediaBase64.includes('base64,')) {
        mediaBase64 = mediaBase64.split('base64,').pop();
    }

    let buffer;
    try {
        buffer = Buffer.from(String(mediaBase64).replace(/\s+/g, ''), 'base64');
    } catch (e) {
        return res.status(400).json({ status: false, message: 'Invalid base64 document payload.' });
    }
    if (!buffer || !buffer.length) {
        return res.status(400).json({ status: false, message: 'Empty document payload.' });
    }

    let cleanTo = String(to).replace(/\D/g, '');
    if (cleanTo.length === 10 && /^[6-9]/.test(cleanTo)) {
        cleanTo = '91' + cleanTo;
    }

    let targetJid = `${cleanTo}@s.whatsapp.net`;
    try {
        if (typeof session.sock.onWhatsApp === 'function') {
            const checkRes = await session.sock.onWhatsApp(cleanTo);
            if (Array.isArray(checkRes) && checkRes.length > 0) {
                const found = checkRes.find(c => c && c.exists) || checkRes[0];
                if (found && found.jid) {
                    targetJid = found.jid;
                }
            }
        }
    } catch (checkErr) {
        console.warn(`[Bridge] onWhatsApp check warning for ${cleanTo}:`, checkErr.message);
    }

    try {
        if (typeof session.sock.sendPresenceUpdate === 'function') {
            await session.sock.sendPresenceUpdate('composing', targetJid).catch(() => {});
        }
    } catch (presenceErr) {}

    try {
        const sentMsg = await session.sock.sendMessage(targetJid, {
            document: buffer,
            mimetype: String(mimetype),
            fileName: fileName,
            caption: String(caption || '')
        });
        return res.json({
            status: true,
            success: true,
            message_id: sentMsg?.key?.id || null,
            to: cleanTo,
            jid: targetJid,
            from: session.phone || null,
            timestamp: new Date().toISOString()
        });
    } catch (err) {
        console.error(`[Bridge] Error sending document on session ${sessionId} to ${cleanTo}:`, err.message);
        return res.status(500).json({
            status: false,
            message: 'Failed to send WhatsApp document: ' + (err.message || String(err)),
            error: err.stack || null
        });
    }
});

// 3d. Graceful reload / restart route
app.post('/restart', (req, res) => {
    res.json({ status: true, message: 'WhatsApp session bridge restarting...' });
    setTimeout(() => {
        process.exit(0);
    }, 400);
});

// 4. Fetch all groups for a connected session
app.get('/sessions/:sessionId/groups', async (req, res) => {
    const { sessionId } = req.params;
    const session = sessions.get(sessionId);

    if (!session || session.status !== 'connected' || !session.sock) {
        return res.status(400).json({
            status: false,
            message: 'Session is not connected to WhatsApp. Please scan QR code first.'
        });
    }

    const persistMaps = () => persistSessionMaps(session);
    const setContactEntry = (key, phone, name) => setSessionContactEntry(session, key, phone, name);

    try {
        const groupsData = await session.sock.groupFetchAllParticipating();
        const groups = Object.values(groupsData).map(g => {
            const count = (Array.isArray(g.participants) && g.participants.length > 0)
                ? g.participants.length
                : (Number(g.size) || 0);

            // Harvest owner mapping if available in group list
            if (g.owner && (g.ownerPn || g.creator_pn)) {
                const ownerPn = String(g.ownerPn || g.creator_pn).split('@')[0].split(':')[0].replace(/\D/g, '');
                const ownerLid = String(g.owner).split('@')[0].split(':')[0].replace(/\D/g, '');
                if (ownerPn && ownerLid && ownerPn !== ownerLid && ownerPn.length >= 7 && ownerPn.length <= 15) {
                    session.lidMap.set(g.owner, ownerPn);
                    session.lidMap.set(`${ownerLid}@lid`, ownerPn);
                    session.lidMap.set(ownerLid, ownerPn);
                    setContactEntry(g.owner, ownerPn, g.ownerUsername || null);
                    setContactEntry(`${ownerLid}@lid`, ownerPn, g.ownerUsername || null);
                }
            }

            // Harvest participants from groupsData if available
            if (Array.isArray(g.participants)) {
                for (const p of g.participants) {
                    const pnCandidate = p.phoneNumber || p.phone;
                    const lidCandidate = p.lid || (p.id?.endsWith('@lid') ? p.id : null);
                    if (pnCandidate && lidCandidate) {
                        const ownerPn = String(pnCandidate).split('@')[0].split(':')[0].replace(/\D/g, '');
                        const ownerLid = String(lidCandidate).split('@')[0].split(':')[0].replace(/\D/g, '');
                        if (ownerPn && ownerLid && ownerPn !== ownerLid && ownerPn.length >= 7 && ownerPn.length <= 15) {
                            session.lidMap.set(lidCandidate, ownerPn);
                            session.lidMap.set(`${ownerLid}@lid`, ownerPn);
                            session.lidMap.set(ownerLid, ownerPn);
                            setContactEntry(lidCandidate, ownerPn, p.name || p.notify || null);
                            setContactEntry(`${ownerLid}@lid`, ownerPn, p.name || p.notify || null);
                        }
                    }
                }
            }

            return {
                jid: g.id,
                name: g.subject || g.name || 'Unnamed Group',
                participants_count: count,
                creation: g.creation ? new Date(g.creation * 1000).toISOString() : null,
                owner: g.owner || null,
                desc: g.desc ? g.desc.toString() : ''
            };
        });

        persistMaps();

        res.json({
            status: true,
            total: groups.length,
            groups
        });
    } catch (error) {
        console.error(`Error fetching groups for ${sessionId}:`, error);
        res.status(500).json({ status: false, message: error.message });
    }
});

// 5. Fetch participants of a specific group
app.get('/sessions/:sessionId/groups/:groupJid/participants', async (req, res) => {
    const { sessionId, groupJid } = req.params;
    const session = sessions.get(sessionId);

    if (!session || session.status !== 'connected' || !session.sock) {
        return res.status(400).json({
            status: false,
            message: 'Session is not connected to WhatsApp.'
        });
    }

    const persistMaps = () => persistSessionMaps(session);
    const setContactEntry = (key, phone, name) => setSessionContactEntry(session, key, phone, name);

    try {
        // Sync any newly saved disk mappings
        syncLidMappingsFromDisk(session, session.authDir);

        const metadata = await session.sock.groupMetadata(groupJid);
        const rawParticipants = metadata.participants || [];

        // Query the raw XML BinaryNode from WhatsApp to inspect raw participant attributes
        const rawAttrsMap = new Map();
        try {
            const rawResult = await session.sock.query({
                tag: 'iq',
                attrs: { type: 'get', xmlns: 'w:g2', to: groupJid },
                content: [{ tag: 'query', attrs: { request: 'interactive' } }]
            });
            if (rawResult) {
                const groupNode = getBinaryNodeChild(rawResult, 'group');
                if (groupNode) {
                    if (!metadata.subject && groupNode.attrs?.subject) {
                        metadata.subject = groupNode.attrs.subject;
                    }

                    const harvestPair = (lidJid, pnJid, name = null) => {
                        if (!lidJid || !pnJid) return;
                        const cLid = String(lidJid).split('@')[0].split(':')[0].replace(/\D/g, '');
                        const cPn = String(pnJid).split('@')[0].split(':')[0].replace(/\D/g, '');
                        if (cLid && cPn && cLid !== cPn && cPn.length >= 7 && cPn.length <= 15) {
                            session.lidMap.set(lidJid, cPn);
                            session.lidMap.set(`${cLid}@lid`, cPn);
                            session.lidMap.set(cLid, cPn);
                            setContactEntry(lidJid, cPn, name);
                            setContactEntry(`${cLid}@lid`, cPn, name);
                            setContactEntry(cLid, cPn, name);
                            setContactEntry(cPn, cPn, name);
                            setContactEntry(`${cPn}@s.whatsapp.net`, cPn, name);
                        }
                    };

                    if (groupNode.attrs) {
                        harvestPair(groupNode.attrs.creator, groupNode.attrs.creator_pn);
                        harvestPair(groupNode.attrs.s_o, groupNode.attrs.s_o_pn);
                    }
                    const descNode = getBinaryNodeChild(groupNode, 'description');
                    if (descNode?.attrs) {
                        harvestPair(descNode.attrs.participant, descNode.attrs.participant_pn);
                    }
                    const pNodes = getBinaryNodeChildren(groupNode, 'participant');
                    for (const pNode of pNodes) {
                        if (pNode?.attrs?.jid) {
                            rawAttrsMap.set(pNode.attrs.jid, pNode.attrs);
                            const cleanDigits = pNode.attrs.jid.split('@')[0].split(':')[0].replace(/\D/g, '');
                            if (cleanDigits) {
                                rawAttrsMap.set(cleanDigits, pNode.attrs);
                                rawAttrsMap.set(`${cleanDigits}@lid`, pNode.attrs);
                            }
                            const pName = pNode.attrs.display_name || pNode.attrs.notify || pNode.attrs.name || pNode.attrs.username;
                            if (pName) {
                                setContactEntry(pNode.attrs.jid, null, pName);
                                if (cleanDigits) {
                                    setContactEntry(cleanDigits, null, pName);
                                    setContactEntry(`${cleanDigits}@lid`, null, pName);
                                }
                            }
                            if (pNode.attrs.phone_number) {
                                harvestPair(pNode.attrs.jid, pNode.attrs.phone_number, pName);
                            }
                            if (pNode.attrs.lid) {
                                harvestPair(pNode.attrs.lid, pNode.attrs.jid, pName);
                            }
                        }
                    }
                }
            }
        } catch (rawErr) {
            // raw query fallback
        }

        // Also harvest creator / owner phone from metadata
        if (metadata.owner && (metadata.ownerPn || metadata.ownerJid || metadata.creator_pn)) {
            const ownerPn = (metadata.ownerPn || metadata.ownerJid || metadata.creator_pn).split('@')[0].split(':')[0].replace(/\D/g, '');
            const ownerLid = metadata.owner.split('@')[0].split(':')[0].replace(/\D/g, '');
            if (ownerPn && ownerLid && ownerPn !== ownerLid && ownerPn.length >= 7 && ownerPn.length <= 15) {
                session.lidMap.set(metadata.owner, ownerPn);
                session.lidMap.set(`${ownerLid}@lid`, ownerPn);
                session.lidMap.set(ownerLid, ownerPn);
                setContactEntry(metadata.owner, ownerPn, metadata.ownerUsername || null);
                setContactEntry(`${ownerLid}@lid`, ownerPn, metadata.ownerUsername || null);
            }
        }

        const myPhone = (session.phone || session.sock?.user?.id || session.sock?.authState?.creds?.me?.id || '')
            .split('@')[0].split(':')[0].replace(/\D/g, '');
        const myLid = (session.sock?.user?.lid || session.sock?.authState?.creds?.me?.lid || '')
            .split('@')[0].split(':')[0].replace(/\D/g, '');

        if (myLid && myPhone) {
            session.lidMap.set(myLid, myPhone);
            session.lidMap.set(`${myLid}@lid`, myPhone);
            setContactEntry(`${myLid}@lid`, myPhone, session.name || session.sock?.user?.name);
            setContactEntry(myLid, myPhone, session.name || session.sock?.user?.name);
        }

        // Batch resolve all participant LIDs via signalRepository if supported
        const allParticipantLids = rawParticipants
            .map(p => {
                const rawId = p.id || '';
                const clean = (rawId.endsWith('@lid') ? rawId : (p.lid || '')).replace(/\D/g, '');
                return clean ? `${clean}@lid` : null;
            })
            .filter(Boolean);

        if (allParticipantLids.length > 0 && session.sock?.signalRepository?.lidMapping?.getPNsForLIDs) {
            try {
                const batchResults = await session.sock.signalRepository.lidMapping.getPNsForLIDs(allParticipantLids);
                if (batchResults && Array.isArray(batchResults)) {
                    for (const item of batchResults) {
                        if (item?.lid && item?.pn) {
                            const cLid = String(item.lid).split('@')[0].split(':')[0].replace(/\D/g, '');
                            const cPn = String(item.pn).split('@')[0].split(':')[0].replace(/\D/g, '');
                            if (cLid && cPn && cLid !== cPn && cPn.length >= 7 && cPn.length <= 15) {
                                session.lidMap.set(item.lid, cPn);
                                session.lidMap.set(`${cLid}@lid`, cPn);
                                session.lidMap.set(cLid, cPn);
                                setContactEntry(item.lid, cPn, null);
                                setContactEntry(`${cLid}@lid`, cPn, null);
                                setContactEntry(cLid, cPn, null);
                            }
                        }
                    }
                }
            } catch (batchErr) {
                // non-blocking
            }
        }

        const participants = await Promise.all(rawParticipants.map(async (p) => {
            const rawId = p.id || '';
            const isLid = rawId.endsWith('@lid') || Boolean(p.lid);
            const cleanLidDigits = (rawId.endsWith('@lid') ? rawId : (p.lid || '')).replace(/\D/g, '');
            const targetLid = rawId.endsWith('@lid') ? rawId : (p.lid || (cleanLidDigits ? `${cleanLidDigits}@lid` : ''));

            const rawAttrs = rawAttrsMap.get(rawId) || rawAttrsMap.get(cleanLidDigits) || rawAttrsMap.get(targetLid) || {};

            let phone = null;
            let jid = rawId;

            // 1. Check raw XML phone_number attribute or SDK p.phoneNumber / p.phone
            const directPhoneCandidate = rawAttrs.phone_number || p.phoneNumber || p.phone;
            if (directPhoneCandidate) {
                const clean = String(directPhoneCandidate).split('@')[0].split(':')[0].replace(/\D/g, '');
                if (clean && clean !== cleanLidDigits && clean.length >= 7 && clean.length <= 15) {
                    phone = clean;
                }
            }

            // 2. Check if rawId is already a phone number JID
            if (!phone && rawId.endsWith('@s.whatsapp.net')) {
                phone = rawId.split('@')[0].split(':')[0].replace(/\D/g, '');
                jid = rawId;
            }

            // 3. Check if this is the connected user themselves
            if (!phone && myLid && myPhone && (cleanLidDigits === myLid || rawId.includes(myLid))) {
                phone = myPhone;
            }

            // 4. Check if this is the group owner / creator
            if (!phone && (p.id === metadata.owner || rawId === metadata.owner || (metadata.owner && cleanLidDigits === metadata.owner.replace(/\D/g, '')))) {
                const ownerPn = metadata.ownerPn || metadata.ownerJid || metadata.creator_pn;
                if (ownerPn) {
                    const clean = ownerPn.split('@')[0].split(':')[0].replace(/\D/g, '');
                    if (clean && clean !== cleanLidDigits && clean.length >= 7 && clean.length <= 15) {
                        phone = clean;
                    }
                }
            }

            // 5. If it's an LID and not yet resolved, use signalRepository.lidMapping.getPNForLID
            if (!phone && isLid && targetLid && session.sock?.signalRepository?.lidMapping?.getPNForLID) {
                try {
                    const pn = await session.sock.signalRepository.lidMapping.getPNForLID(targetLid)
                        || (cleanLidDigits ? await session.sock.signalRepository.lidMapping.getPNForLID(`${cleanLidDigits}@lid`) : null);
                    if (pn) {
                        const clean = String(pn).split('@')[0].split(':')[0].replace(/\D/g, '');
                        if (clean && clean !== cleanLidDigits && clean.length >= 7 && clean.length <= 15) {
                            phone = clean;
                        }
                    }
                } catch (err) {}
            }

            // 6. Check lidMap cache
            if (!phone && isLid && session.lidMap) {
                const mapped = session.lidMap.get(targetLid)
                    || session.lidMap.get(`${cleanLidDigits}@lid`)
                    || session.lidMap.get(cleanLidDigits);
                if (mapped) {
                    const clean = String(mapped).replace(/\D/g, '');
                    if (clean && clean !== cleanLidDigits && clean.length >= 7 && clean.length <= 15) {
                        phone = clean;
                    }
                }
            }

            // 7. Check contacts store cache
            if (!phone && isLid && session.contacts) {
                const c = session.contacts.get(targetLid)
                    || session.contacts.get(`${cleanLidDigits}@lid`)
                    || session.contacts.get(cleanLidDigits);
                if (c && c.phone) {
                    const clean = String(c.phone).replace(/\D/g, '');
                    if (clean && clean !== cleanLidDigits && clean.length >= 7 && clean.length <= 15) {
                        phone = clean;
                    }
                }
            }

            // Name resolution from rawAttrs, SDK object, or contacts store
            let name = null;
            const nameCandidates = [
                rawAttrs.display_name,
                rawAttrs.name,
                rawAttrs.notify,
                rawAttrs.username,
                rawAttrs.participant_username,
                p.name,
                p.notify,
                p.username
            ];

            for (const cand of nameCandidates) {
                if (cand && typeof cand === 'string' && cand.trim().length > 0) {
                    name = cand.trim();
                    break;
                }
            }

            // Owner username fallback
            if (!name && (p.id === metadata.owner || rawId === metadata.owner || (metadata.owner && cleanLidDigits === metadata.owner.replace(/\D/g, '')))) {
                if (metadata.ownerUsername) {
                    name = metadata.ownerUsername;
                }
            }

            // Name lookup from contacts cache
            if (!name && session.contacts) {
                const lookupKeys = [
                    targetLid,
                    cleanLidDigits ? `${cleanLidDigits}@lid` : null,
                    cleanLidDigits,
                    phone ? `${phone}@s.whatsapp.net` : null,
                    phone,
                    rawId,
                    p.id
                ].filter(Boolean);

                for (const key of lookupKeys) {
                    const c = session.contacts.get(key);
                    if (c && c.name && typeof c.name === 'string' && c.name.trim().length > 0) {
                        name = c.name.trim();
                        break;
                    }
                }
            }

            // Fallback for self
            if (!name && phone && phone === myPhone) {
                name = session.name || session.sock?.user?.name || null;
            }

            // If phone was resolved from LID, cache in lidMap and contacts store
            if (phone && isLid && targetLid) {
                session.lidMap.set(targetLid, phone);
                session.lidMap.set(`${cleanLidDigits}@lid`, phone);
                session.lidMap.set(cleanLidDigits, phone);
                setContactEntry(targetLid, phone, name);
                setContactEntry(`${cleanLidDigits}@lid`, phone, name);
                setContactEntry(cleanLidDigits, phone, name);
                setContactEntry(phone, phone, name);
                setContactEntry(`${phone}@s.whatsapp.net`, phone, name);

                try {
                    if (session.sock?.signalRepository?.lidMapping?.storeLIDPNMappings) {
                        await session.sock.signalRepository.lidMapping.storeLIDPNMappings([{
                            lid: targetLid,
                            pn: `${phone}@s.whatsapp.net`
                        }]);
                    }
                } catch (e) {}
            }

            const isAdmin = p.admin === 'admin' || p.admin === 'superadmin' || rawAttrs.type === 'admin' || rawAttrs.type === 'superadmin';

            // Never return LID digits as phone! Return phone: null if unresolved.
            // Return name: null if unknown (remove "Member xxxx" and "Group Admin" fallbacks).
            return {
                jid: phone ? `${phone}@s.whatsapp.net` : (rawId || targetLid),
                phone: phone || null,
                raw_id: rawId,
                is_lid: Boolean(isLid && !phone),
                name: name ? name.trim() : null,
                isAdmin: Boolean(isAdmin)
            };
        }));

        persistMaps();

        res.json({
            status: true,
            group_name: metadata.subject || metadata.name || 'WhatsApp Group',
            total: participants.length,
            participants
        });
    } catch (error) {
        console.error(`Error fetching participants for ${sessionId} group ${groupJid}:`, error);
        res.status(500).json({ status: false, message: error.message });
    }
});

// Global 404 handler (always return JSON, never default Express HTML)
app.use((req, res) => {
    res.status(404).json({
        status: false,
        message: `Endpoint not found on WhatsApp session bridge: ${req.method} ${req.originalUrl || req.url}`
    });
});

// Global error handler (always return JSON)
app.use((err, req, res, next) => {
    console.error('[Bridge] Express error:', err);
    res.status(500).json({
        status: false,
        message: err?.message || 'Internal bridge error',
        error: err?.stack || null
    });
});

// Restore previously active sessions on boot
async function restoreSavedSessions() {
    try {
        if (!fs.existsSync(SESSIONS_DIR)) return;
        const subdirs = fs.readdirSync(SESSIONS_DIR, { withFileTypes: true });
        for (const dirent of subdirs) {
            if (dirent.isDirectory()) {
                const credsFile = path.join(SESSIONS_DIR, dirent.name, 'creds.json');
                if (fs.existsSync(credsFile)) {
                    console.log(`[WhatsApp Session] Restoring saved session: ${dirent.name}`);
                    await startSession(dirent.name).catch(err => {
                        console.error(`Failed to restore ${dirent.name}:`, err.message);
                    });
                }
            }
        }
    } catch (err) {
        console.error('Error restoring sessions:', err);
    }
}

// 1. TCP Port Listener (Windows XAMPP & Linux local)
try {
    const tcpServer = app.listen(PORT, '127.0.0.1', () => {
        console.log(`WhatsApp Multi-Device Session Bridge running on port ${PORT}`);
    });
    tcpServer.on('error', (e) => {
        console.error('TCP Port Listen Notice:', e.message);
    });
} catch (e) {
    console.error('TCP Port Listen Error:', e.message);
}

// 2. UNIX Domain Socket Listener (Essential for Hostinger / CageFS shared hosting)
if (process.platform !== 'win32') {
    // Linux sockaddr_un.sun_path has a hard limit of 108 bytes; deep shared-hosting paths cause cURL error 6.
    // Use /tmp/wa_session.sock which is only 20 bytes and well within the limit.
    const defaultSocket = fs.existsSync('/tmp') ? '/tmp/wa_session.sock' : path.join(__dirname, 'whatsapp.sock');
    const SOCKET_PATH = process.env.WHATSAPP_SESSION_SOCKET || defaultSocket;
    if (fs.existsSync(SOCKET_PATH)) {
        try { fs.unlinkSync(SOCKET_PATH); } catch (e) {}
    }
    const unixServer = http.createServer(app);
    unixServer.on('error', (err) => {
        if (err.code === 'EADDRINUSE') {
            console.error(`[Notice] Socket ${SOCKET_PATH} is already in use by another running instance.`);
        } else {
            console.error('UNIX Socket Error:', err.message);
        }
    });
    unixServer.listen(SOCKET_PATH, () => {
        try { fs.chmodSync(SOCKET_PATH, 0o777); } catch (e) {}
        console.log(`WhatsApp Multi-Device Session Bridge running on socket ${SOCKET_PATH}`);
    });
}

restoreSavedSessions();
