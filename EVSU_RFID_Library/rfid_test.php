<?php
declare(strict_types=1);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RFID Scanner Test Page</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0d1117;
            --card: #161b22;
            --line: #30363d;
            --text: #e6edf3;
            --muted: #8b949e;
            --accent: #2f81f7;
            --ok: #238636;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 18px;
        }
        .wrap { max-width: 980px; margin: 0 auto; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
        }
        h1, h2, p { margin: 0 0 10px; }
        p { color: var(--muted); }
        code {
            background: #0b1016;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 1px 6px;
        }
        .row { display: flex; gap: 8px; flex-wrap: wrap; }
        input, button {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            background: #0b1016;
            color: var(--text);
        }
        input { min-width: 260px; flex: 1; }
        button {
            cursor: pointer;
            font-weight: 600;
            background: var(--accent);
            border-color: #1f6feb;
        }
        .btn-ok {
            background: var(--ok);
            border-color: #2ea043;
        }
        .btn-muted {
            background: #21262d;
            border-color: #30363d;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 12px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            border-bottom: 1px solid var(--line);
            text-align: left;
            padding: 8px 6px;
            font-size: 14px;
        }
        th { color: var(--muted); font-weight: 600; }
        .small { font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>RFID Scanner Test</h1>
        <p>Open this page while scanning cards. Supports both keyboard-like scanners and Arduino HTTP send.</p>
        <span class="badge">Auto-refresh: every 1 second</span>
        <span class="badge">Keyboard scan: ON</span>
    </div>

    <div class="card">
        <h2>Manual Test Scan</h2>
        <div class="row">
            <input id="uidInput" placeholder="Enter UID (example: A1B2C3D4)" maxlength="64">
            <input id="deviceInput" placeholder="Device name (optional)" value="nano-rfid" maxlength="64">
            <button class="btn-ok" id="sendBtn">Send Scan</button>
            <button class="btn-muted" id="connectSerialBtn" type="button">Connect Nano Serial</button>
        </div>
        <p class="small" id="statusText">Waiting for scans...</p>
    </div>

    <div class="card">
        <h2>Arduino Request Example</h2>
        <p class="small">POST JSON to this URL from your ESP8266/ESP32/Ethernet client:</p>
        <p><code id="apiUrlText">http://localhost/EVSU_RFID_Library/rfid_scan_api.php</code></p>
        <p class="small">If Arduino is a separate device, do <b>not</b> use localhost. Use your PC LAN IP (example: <code>http://192.168.1.10/EVSU_RFID_Library/rfid_scan_api.php</code>).</p>
        <p class="small">Body example: <code>{"uid":"A1B2C3D4","device":"nano-rfid"}</code></p>
    </div>

    <div class="card">
        <h2>Recent Scans</h2>
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>UID</th>
                <th>Device</th>
                <th>Time</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody id="scanRows">
            <tr><td colspan="5" class="small">No scans yet.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
const apiUrl = 'rfid_scan_api.php';
const uidInput = document.getElementById('uidInput');
const deviceInput = document.getElementById('deviceInput');
const sendBtn = document.getElementById('sendBtn');
const connectSerialBtn = document.getElementById('connectSerialBtn');
const scanRows = document.getElementById('scanRows');
const statusText = document.getElementById('statusText');
const apiUrlText = document.getElementById('apiUrlText');

let keyScanBuffer = '';
let keyScanLastAt = 0;
let serialReader = null;
let serialPort = null;
let serialBuffer = '';

async function loadScans() {
    try {
        const res = await fetch(apiUrl, { cache: 'no-store' });
        const data = await res.json();
        const items = Array.isArray(data.items) ? data.items : [];

        if (items.length === 0) {
            scanRows.innerHTML = '<tr><td colspan="5" class="small">No scans yet.</td></tr>';
            return;
        }

        scanRows.innerHTML = items.map((row, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td><code>${escapeHtml(row.uid || '')}</code></td>
                <td>${escapeHtml(row.device || '-')}</td>
                <td>${escapeHtml(row.timestamp || '-')}</td>
                <td>${escapeHtml(row.ip || '-')}</td>
            </tr>
        `).join('');
    } catch (err) {
        statusText.textContent = 'Failed to read scans: ' + err;
    }
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

async function sendManualScan() {
    const uid = uidInput.value.trim();
    const device = deviceInput.value.trim() || 'manual-test';
    if (!uid) {
        statusText.textContent = 'UID is required.';
        uidInput.focus();
        return;
    }

    sendBtn.disabled = true;
    statusText.textContent = 'Sending scan...';
    try {
        const res = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ uid, device }),
        });
        const data = await res.json();
        if (!data.ok) {
            statusText.textContent = 'Scan failed: ' + (data.message || 'unknown error');
            return;
        }
        statusText.textContent = 'Scan saved: ' + uid;
        uidInput.value = '';
        await loadScans();
    } catch (err) {
        statusText.textContent = 'Send failed: ' + err;
    } finally {
        sendBtn.disabled = false;
    }
}

async function sendScan(uid, device) {
    const cleanUid = String(uid || '').trim();
    const cleanDevice = String(device || '').trim() || 'manual-test';
    if (!cleanUid) {
        return;
    }

    const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ uid: cleanUid, device: cleanDevice }),
    });
    const data = await res.json();
    if (!data.ok) {
        throw new Error(data.message || 'unknown error');
    }
}

sendBtn.addEventListener('click', sendManualScan);
uidInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') sendManualScan();
});

// Keyboard-mode scanners usually type UID fast and press Enter.
document.addEventListener('keydown', async (e) => {
    const active = document.activeElement;
    const typingInField = active === uidInput || active === deviceInput;
    if (typingInField) return;

    const now = Date.now();
    if (now - keyScanLastAt > 300) {
        keyScanBuffer = '';
    }
    keyScanLastAt = now;

    if (e.key === 'Enter') {
        const uid = keyScanBuffer.trim();
        keyScanBuffer = '';
        if (!uid) return;
        statusText.textContent = 'Keyboard scan detected: ' + uid;
        try {
            await sendScan(uid, 'keyboard-rfid');
            await loadScans();
        } catch (err) {
            statusText.textContent = 'Keyboard send failed: ' + err;
        }
        return;
    }

    if (e.key.length === 1) {
        keyScanBuffer += e.key;
    }
});

if (apiUrlText) {
    apiUrlText.textContent = window.location.origin + window.location.pathname.replace('rfid_test.php', 'rfid_scan_api.php');
}

function normalizeUid(raw) {
    return String(raw || '')
        .replaceAll('UID:', '')
        .replaceAll(/\s+/g, '')
        .trim()
        .toUpperCase();
}

async function connectSerial() {
    if (!('serial' in navigator)) {
        statusText.textContent = 'Web Serial is not supported. Use Chrome/Edge.';
        return;
    }

    try {
        serialPort = await navigator.serial.requestPort();
        await serialPort.open({ baudRate: 9600 });
        statusText.textContent = 'Nano serial connected. Scan a card now.';
        connectSerialBtn.disabled = true;
        connectSerialBtn.textContent = 'Serial Connected';

        const decoder = new TextDecoderStream();
        serialPort.readable.pipeTo(decoder.writable).catch(() => {});
        serialReader = decoder.readable.getReader();

        while (true) {
            const { value, done } = await serialReader.read();
            if (done) break;
            if (!value) continue;

            serialBuffer += value;
            const lines = serialBuffer.split(/\r?\n/);
            serialBuffer = lines.pop() || '';

            for (const lineRaw of lines) {
                const line = lineRaw.trim();
                if (!line) continue;
                if (!line.toUpperCase().startsWith('UID:')) continue;

                const uid = normalizeUid(line);
                if (!uid) continue;

                statusText.textContent = 'Serial scan detected: ' + uid;
                try {
                    await sendScan(uid, 'nano-serial');
                    await loadScans();
                } catch (err) {
                    statusText.textContent = 'Serial send failed: ' + err;
                }
            }
        }
    } catch (err) {
        statusText.textContent = 'Serial connect failed: ' + err;
    } finally {
        connectSerialBtn.disabled = false;
        connectSerialBtn.textContent = 'Connect Nano Serial';
        if (serialReader) {
            try { await serialReader.cancel(); } catch (_) {}
            serialReader = null;
        }
        if (serialPort) {
            try { await serialPort.close(); } catch (_) {}
            serialPort = null;
        }
    }
}

if (connectSerialBtn) {
    connectSerialBtn.addEventListener('click', connectSerial);
}

loadScans();
setInterval(loadScans, 1000);
</script>
</body>
</html>
