#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// SOLA xAI Realtime WebSocket proxy.
//
// Purpose: keep the xAI master API key on the server side. The Moodle
// plugin mints a short-lived HS256 JWT for a specific learner + course +
// nonce; the browser opens a WebSocket to this proxy carrying the JWT;
// the proxy validates the JWT, opens a backend WebSocket to xAI with the
// real API key from its own environment, and relays frames in both
// directions until either side disconnects.
//
// Env:
//   XAI_API_KEY           — the xAI API key (required).
//   MOODLE_JWT_SECRET     — HS256 shared secret used by Moodle to sign
//                           tokens (required). Same value goes into the
//                           `xai_proxy_jwt_secret` SOLA admin setting.
//   PROXY_LISTEN_PORT     — TCP port to bind (default 8787).
//   PROXY_LISTEN_HOST     — Bind host (default 127.0.0.1).
//   XAI_REALTIME_URL      — Override upstream URL (default
//                           wss://api.x.ai/v1/realtime).
//   LOG_LEVEL             — info | debug (default info).

'use strict';

const http = require('http');
const crypto = require('crypto');
const WebSocket = require('ws');

const XAI_API_KEY = process.env.XAI_API_KEY;
const JWT_SECRET = process.env.MOODLE_JWT_SECRET;
const PORT = parseInt(process.env.PROXY_LISTEN_PORT || '8787', 10);
const HOST = process.env.PROXY_LISTEN_HOST || '127.0.0.1';
const XAI_URL = process.env.XAI_REALTIME_URL || 'wss://api.x.ai/v1/realtime';
const LOG_LEVEL = (process.env.LOG_LEVEL || 'info').toLowerCase();

if (!XAI_API_KEY) {
    console.error('FATAL: XAI_API_KEY env var is required.');
    process.exit(1);
}
if (!JWT_SECRET) {
    console.error('FATAL: MOODLE_JWT_SECRET env var is required.');
    process.exit(1);
}

function log(level, msg, extra) {
    if (level === 'debug' && LOG_LEVEL !== 'debug') { return; }
    const line = { ts: new Date().toISOString(), level, msg };
    if (extra) { Object.assign(line, extra); }
    console.log(JSON.stringify(line));
}

function b64urlDecode(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) { s += '='; }
    return Buffer.from(s, 'base64');
}

function timingSafeEqual(a, b) {
    if (a.length !== b.length) { return false; }
    return crypto.timingSafeEqual(a, b);
}

// Single-process nonce cache to reject replay within the token TTL.
const seenNonces = new Map();
function rememberNonce(nonce, exp) {
    seenNonces.set(nonce, exp);
    if (seenNonces.size > 5000) {
        const now = Math.floor(Date.now() / 1000);
        for (const [k, e] of seenNonces) {
            if (e < now) { seenNonces.delete(k); }
        }
    }
}

function verifyJwt(token) {
    const parts = token.split('.');
    if (parts.length !== 3) { return { ok: false, reason: 'malformed' }; }
    const [h, p, s] = parts;
    let header, payload;
    try {
        header = JSON.parse(b64urlDecode(h).toString('utf8'));
        payload = JSON.parse(b64urlDecode(p).toString('utf8'));
    } catch (e) {
        return { ok: false, reason: 'decode_error' };
    }
    if (header.alg !== 'HS256' || header.typ !== 'JWT') {
        return { ok: false, reason: 'bad_alg' };
    }
    const signing = `${h}.${p}`;
    const expected = crypto.createHmac('sha256', JWT_SECRET).update(signing).digest();
    const got = b64urlDecode(s);
    if (!timingSafeEqual(expected, got)) {
        return { ok: false, reason: 'bad_signature' };
    }
    const now = Math.floor(Date.now() / 1000);
    if (typeof payload.exp !== 'number' || payload.exp < now) {
        return { ok: false, reason: 'expired' };
    }
    if (typeof payload.nbf === 'number' && payload.nbf > now + 5) {
        return { ok: false, reason: 'not_yet_valid' };
    }
    if (payload.provider !== 'xai') {
        return { ok: false, reason: 'wrong_provider' };
    }
    if (!payload.nonce || seenNonces.has(payload.nonce)) {
        return { ok: false, reason: 'replay' };
    }
    rememberNonce(payload.nonce, payload.exp);
    return { ok: true, payload };
}

// HTTP server for the WebSocket upgrade and a /health endpoint.
const server = http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, upstream: XAI_URL }));
        return;
    }
    res.writeHead(404);
    res.end();
});

const wss = new WebSocket.Server({ noServer: true, clientTracking: false });

server.on('upgrade', (req, socket, head) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname !== '/rt') {
        socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
        socket.destroy();
        return;
    }
    const token = url.searchParams.get('token') || '';
    const verdict = verifyJwt(token);
    if (!verdict.ok) {
        log('info', 'auth_reject', { reason: verdict.reason });
        socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
        socket.destroy();
        return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => {
        relaySession(ws, verdict.payload);
    });
});

function relaySession(clientWs, claims) {
    const sessionId = crypto.randomBytes(6).toString('hex');
    const voice = (claims.voice || 'eve').replace(/[^a-zA-Z0-9_-]/g, '');
    const upstreamUrl = XAI_URL + (XAI_URL.includes('?') ? '&' : '?') + 'voice=' + encodeURIComponent(voice);
    log('info', 'session_open', {
        sid: sessionId,
        userid: claims.sub,
        courseid: claims.courseid,
        voice: voice,
    });
    const upstream = new WebSocket(upstreamUrl, {
        headers: {
            'Authorization': 'Bearer ' + XAI_API_KEY,
        },
    });
    const startedAt = Date.now();

    let closed = false;
    const teardown = (who, code, reason) => {
        if (closed) { return; }
        closed = true;
        log('info', 'session_close', {
            sid: sessionId,
            userid: claims.sub,
            initiator: who,
            code: code,
            reason: String(reason || ''),
            duration_ms: Date.now() - startedAt,
        });
        try { clientWs.close(code || 1000, 'closed'); } catch (e) { /* ignore */ }
        try { upstream.close(code || 1000, 'closed'); } catch (e) { /* ignore */ }
    };

    upstream.on('open', () => {
        log('debug', 'upstream_open', { sid: sessionId });
    });
    upstream.on('message', (data, isBinary) => {
        if (clientWs.readyState === WebSocket.OPEN) {
            clientWs.send(data, { binary: isBinary });
        }
    });
    upstream.on('close', (code, reason) => teardown('upstream', code, reason));
    upstream.on('error', (err) => {
        log('info', 'upstream_error', { sid: sessionId, err: String(err) });
        teardown('upstream', 1011, String(err));
    });

    clientWs.on('message', (data, isBinary) => {
        if (upstream.readyState === WebSocket.OPEN) {
            upstream.send(data, { binary: isBinary });
        }
    });
    clientWs.on('close', (code, reason) => teardown('client', code, reason));
    clientWs.on('error', (err) => {
        log('info', 'client_error', { sid: sessionId, err: String(err) });
        teardown('client', 1011, String(err));
    });
}

server.listen(PORT, HOST, () => {
    log('info', 'listening', { host: HOST, port: PORT, upstream: XAI_URL });
});

// Graceful shutdown on SIGTERM/SIGINT.
function shutdown(signal) {
    log('info', 'shutting_down', { signal });
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(1), 10000).unref();
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
