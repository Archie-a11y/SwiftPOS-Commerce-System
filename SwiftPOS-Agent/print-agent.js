#!/usr/bin/env node
/**
 * MiniPOS Local Print Agent — forwards raw ESC/POS bytes to a thermal printer.
 *
 * Endpoints:
 *   POST /print   -> body = raw ESC/POS bytes
 *   POST /drawer  -> cash-drawer kick
 *   GET  /status  -> health check
 *
 * Windows (Xprinter XP-80 USB) — recommended:
 *   PRINTER_TYPE=raw
 *   PRINTER_NAME=XP-80        (name shown in Windows Printers list)
 *
 * Network printer:
 *   PRINTER_TYPE=tcp  PRINTER_HOST=192.168.1.50  PRINTER_PORT=9100
 *
 * Serial / COM port:
 *   PRINTER_TYPE=file  PRINTER_FILE=COM3
 */

const http = require('http');
const net = require('net');
const fs = require('fs');
const path = require('path');
const { execFile } = require('child_process');
const os = require('os');

const isWin = process.platform === 'win32';
const RAW_SCRIPT = path.join(__dirname, 'send-raw.ps1');

const CFG = {
  type: process.env.PRINTER_TYPE || (isWin ? 'raw' : 'tcp'),
  // Windows local printer name (e.g. "XP-80" or "XP-80C") — NOT the \\share path
  name: process.env.PRINTER_NAME || 'XP-80C',
  host: process.env.PRINTER_HOST || '192.168.1.50',
  port: Number(process.env.PRINTER_PORT || 9100),
  file: process.env.PRINTER_FILE || 'COM3',
  agentPort: Number(process.env.AGENT_PORT || 9100),
};

const DRAWER_KICK = Buffer.from([0x1b, 0x70, 0x00, 0x19, 0xfa]);

function log(...a) { console.log(new Date().toISOString(), ...a); }

function printerLabel() {
  if (CFG.type === 'tcp') return `${CFG.host}:${CFG.port}`;
  if (CFG.type === 'file') return CFG.file;
  return CFG.name;
}

function writeTmp(buffer) {
  const tmp = path.join(os.tmpdir(), `minipos_${Date.now()}.prn`);
  fs.writeFileSync(tmp, buffer);
  return tmp;
}

function sendRawWindows(buffer) {
  return new Promise((resolve, reject) => {
    const tmp = writeTmp(buffer);
    const name = CFG.name.replace(/^\\\\[^\\]+\\/, ''); // XP-80 from \\localhost\XP-80
    execFile('powershell.exe', [
      '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', RAW_SCRIPT,
      '-PrinterName', name, '-FilePath', tmp,
    ], (err, _out, stderr) => {
      fs.unlink(tmp, () => {});
      if (err) reject(new Error(stderr || err.message));
      else resolve();
    });
  });
}

function sendToPrinter(buffer) {
  return new Promise((resolve, reject) => {
    if (CFG.type === 'tcp') {
      const socket = net.createConnection(CFG.port, CFG.host, () => {
        socket.write(buffer, () => socket.end());
      });
      socket.on('close', resolve);
      socket.on('error', reject);
    } else if (CFG.type === 'file') {
      fs.writeFile(CFG.file, buffer, { flag: 'a' }, (err) => err ? reject(err) : resolve());
    } else if (CFG.type === 'raw' && isWin) {
      sendRawWindows(buffer).then(resolve, reject);
    } else {
      // legacy share — copy /B (may garble ESC/POS on GDI drivers; prefer raw)
      const tmp = writeTmp(buffer);
      const target = CFG.name.includes('\\') ? CFG.name : `\\\\localhost\\${CFG.name}`;
      execFile('cmd', ['/c', 'copy', '/B', tmp, target], (e) => {
        fs.unlink(tmp, () => {});
        e ? reject(e) : resolve();
      });
    }
  });
}

function readBody(req) {
  return new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks)));
  });
}

function cors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
}

const server = http.createServer(async (req, res) => {
  cors(res);
  if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }

  try {
    if (req.url === '/status') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ ok: true, printer: CFG.type, target: printerLabel() }));
    }

    if (req.url === '/print' && req.method === 'POST') {
      const body = await readBody(req);
      await sendToPrinter(body);
      log('Printed', body.length, 'bytes ->', printerLabel());
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ ok: true }));
    }

    if (req.url === '/drawer' && req.method === 'POST') {
      await sendToPrinter(DRAWER_KICK);
      log('Drawer kick sent');
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ ok: true }));
    }

    res.writeHead(404); res.end('Not found');
  } catch (err) {
    log('ERROR', err.message);
    res.writeHead(500, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: false, error: err.message }));
  }
});

server.listen(CFG.agentPort, '127.0.0.1', () => {
  log(`MiniPOS Print Agent on http://127.0.0.1:${CFG.agentPort}`);
  log(`Printer: ${CFG.type} -> ${printerLabel()}`);
  if (isWin && CFG.type === 'raw') {
    log('Using Windows RAW mode (recommended for Xprinter XP-80).');
    log('Set PRINTER_NAME to the exact name in Windows Printers list.');
  }
});
