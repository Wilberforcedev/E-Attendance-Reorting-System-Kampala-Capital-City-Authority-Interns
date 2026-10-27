import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PORT = Number.parseInt(process.env.PORT || '3000', 10);
const HOST = process.env.HOST || '0.0.0.0';
const PHP_API_ORIGIN = process.env.PHP_API_ORIGIN || '';

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.eot': 'application/vnd.ms-fontobject'
};

function sendJson(res, status, payload) {
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff'
  });
  res.end(JSON.stringify(payload));
}

function proxyToPhp(req, res, pathname, search) {
  let origin;
  try {
    origin = new URL(PHP_API_ORIGIN);
    if (!['http:', 'https:'].includes(origin.protocol)) throw new Error('Unsupported protocol');
  } catch {
    sendJson(res, 501, {
      status: 'error',
      code: 'API_NOT_CONFIGURED',
      message: 'PHP API is not configured. Set PHP_API_ORIGIN to the base URL of the running PHP backend.'
    });
    return;
  }

  const transport = origin.protocol === 'https:' ? https : http;
  const upstreamPath = `${pathname}${search}`;
  const upstream = transport.request({
    protocol: origin.protocol,
    hostname: origin.hostname,
    port: origin.port || undefined,
    method: req.method,
    path: upstreamPath,
    headers: {
      ...req.headers,
      host: origin.host,
      connection: 'close'
    }
  }, (upstreamRes) => {
    res.writeHead(upstreamRes.statusCode || 502, upstreamRes.headers);
    upstreamRes.pipe(res);
  });

  upstream.setTimeout(15000, () => {
    upstream.destroy(new Error('PHP API upstream timed out'));
  });
  upstream.on('error', (error) => {
    if (res.headersSent) {
      res.destroy(error);
      return;
    }
    sendJson(res, 502, {
      status: 'error',
      code: 'API_UPSTREAM_UNAVAILABLE',
      message: 'The PHP API could not be reached. Check PHP_API_ORIGIN and confirm the PHP server is running.'
    });
  });
  req.pipe(upstream);
}

const server = http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', process.env.CORS_ALLOW_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
  res.setHeader('X-Content-Type-Options', 'nosniff');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  let parsedUrl;
  let pathname;
  try {
    parsedUrl = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
    pathname = decodeURIComponent(parsedUrl.pathname);
  } catch {
    sendJson(res, 400, { status: 'error', code: 'BAD_REQUEST', message: 'Malformed request URL.' });
    return;
  }

  if (pathname.includes('\\0') || pathname.split('/').some(part => part === '..')) {
    sendJson(res, 400, { status: 'error', code: 'INVALID_PATH', message: 'Invalid request path.' });
    return;
  }

  if (pathname === '/' || pathname === '/admin' || pathname === '/admin/') {
    res.writeHead(302, { Location: '/ears-system/frontend/admin/index.html' });
    res.end();
    return;
  }

  if (['/client', '/client/', '/intern', '/intern/', '/portal', '/portal/'].includes(pathname)) {
    res.writeHead(302, { Location: '/ears-system/frontend/client/index.html' });
    res.end();
    return;
  }

  // Forward PHP/API requests to a separately running PHP backend when configured.
  if (pathname.includes('/api/') || pathname.endsWith('.php')) {
    proxyToPhp(req, res, pathname, parsedUrl.search);
    return;
  }

  const candidates = [
    path.resolve(__dirname, `.${pathname}`),
    path.resolve(__dirname, 'ears-system/frontend/client', `.${pathname}`),
    path.resolve(__dirname, 'ears-system/frontend/admin', `.${pathname}`),
    path.resolve(__dirname, 'ears-system/frontend', `.${pathname}`),
    path.resolve(__dirname, 'frontend', `.${pathname}`)
  ];

  let filePathToServe = null;
  for (const candidate of candidates) {
    // Never serve files outside the intended directory for each candidate root.
    const roots = [
      __dirname,
      path.resolve(__dirname, 'ears-system/frontend/client'),
      path.resolve(__dirname, 'ears-system/frontend/admin'),
      path.resolve(__dirname, 'ears-system/frontend'),
      path.resolve(__dirname, 'frontend')
    ];
    const root = roots[candidates.indexOf(candidate)];
    if (candidate !== root && !candidate.startsWith(root + path.sep)) continue;
    try {
      if (!fs.existsSync(candidate)) continue;
      const stat = fs.statSync(candidate);
      if (stat.isFile()) {
        filePathToServe = candidate;
        break;
      }
      if (stat.isDirectory()) {
        const indexHtml = path.join(candidate, 'index.html');
        if (indexHtml.startsWith(root + path.sep) && fs.existsSync(indexHtml) && fs.statSync(indexHtml).isFile()) {
          filePathToServe = indexHtml;
          break;
        }
      }
    } catch {
      // Continue checking the other permitted static roots.
    }
  }

  if (!filePathToServe) {
    res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><head><title>404 Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:50px"><h2>404 - Page Not Found</h2><p><a href="/ears-system/frontend/admin/index.html">Go to EARS Admin Panel</a></p></body></html>');
    return;
  }

  const contentType = MIME_TYPES[path.extname(filePathToServe).toLowerCase()] || 'application/octet-stream';
  fs.readFile(filePathToServe, (error, data) => {
    if (error) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('500 Internal Server Error');
      return;
    }
    res.writeHead(200, { 'Content-Type': contentType });
    res.end(data);
  });
});

server.listen(PORT, HOST, () => {
  console.log(`EARS server listening on http://${HOST}:${PORT}`);
  if (PHP_API_ORIGIN) console.log(`PHP API proxy enabled: ${PHP_API_ORIGIN}`);
  else console.log('PHP API proxy disabled; PHP/API requests will return HTTP 501 until PHP_API_ORIGIN is configured.');
});
