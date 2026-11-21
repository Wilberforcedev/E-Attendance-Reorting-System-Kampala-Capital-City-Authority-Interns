import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));
const publicRoot = path.join(projectRoot, 'dist');
const port = Number.parseInt(process.env.PORT ?? '3000', 10);
const host = process.env.HOST ?? '0.0.0.0';

const mimeTypes = new Map([
  ['.html', 'text/html; charset=utf-8'],
  ['.css', 'text/css; charset=utf-8'],
  ['.js', 'text/javascript; charset=utf-8'],
  ['.json', 'application/json; charset=utf-8'],
  ['.png', 'image/png'],
  ['.jpg', 'image/jpeg'],
  ['.jpeg', 'image/jpeg'],
  ['.gif', 'image/gif'],
  ['.svg', 'image/svg+xml'],
  ['.ico', 'image/x-icon'],
  ['.woff', 'font/woff'],
  ['.woff2', 'font/woff2'],
  ['.ttf', 'font/ttf'],
]);

function send(res, status, body, contentType = 'text/plain; charset=utf-8') {
  res.writeHead(status, {
    'Content-Type': contentType,
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

function addSecurityHeaders(res) {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Permissions-Policy', 'camera=(), microphone=()');
}

async function resolvePublicFile(pathname) {
  const relativePath = pathname.replace(/^\/+/, '');
  const candidate = path.resolve(publicRoot, relativePath || 'index.html');
  const resolvedRoot = await fs.realpath(publicRoot).catch(() => null);

  if (candidate !== publicRoot && !candidate.startsWith(`${publicRoot}${path.sep}`)) {
    return null;
  }
  if (!resolvedRoot) {
    return null;
  }

  try {
    const resolvedFile = await fs.realpath(candidate);
    if (resolvedFile !== resolvedRoot && !resolvedFile.startsWith(`${resolvedRoot}${path.sep}`)) {
      return null;
    }
    const stat = await fs.stat(resolvedFile);
    if (stat.isDirectory()) {
      return resolvePublicFile(path.join(pathname, 'index.html'));
    }
    return stat.isFile() ? resolvedFile : null;
  } catch {
    return null;
  }
}

const server = http.createServer(async (req, res) => {
  addSecurityHeaders(res);

  if (!['GET', 'HEAD'].includes(req.method ?? '')) {
    res.setHeader('Allow', 'GET, HEAD');
    send(res, 405, 'Method Not Allowed');
    return;
  }

  let url;
  try {
    url = new URL(req.url ?? '/', 'http://localhost');
    decodeURIComponent(url.pathname);
  } catch {
    send(res, 400, 'Bad Request');
    return;
  }

  const pathname = url.pathname;

  if (pathname === '/healthz') {
    send(res, 200, 'ok');
    return;
  }

  const redirectPaths = new Map([
    ['/', '/ears-system/frontend/login.html'],
    ['/login', '/ears-system/frontend/login.html'],
    ['/login/', '/ears-system/frontend/login.html'],
    ['/frontend/login.html', '/ears-system/frontend/login.html'],
    ['/admin', '/ears-system/frontend/admin/index.html'],
    ['/admin/', '/ears-system/frontend/admin/index.html'],
    ['/client', '/ears-system/frontend/client/index.html'],
    ['/client/', '/ears-system/frontend/client/index.html'],
    ['/intern', '/ears-system/frontend/client/index.html'],
    ['/intern/', '/ears-system/frontend/client/index.html'],
    ['/portal', '/ears-system/frontend/client/index.html'],
    ['/portal/', '/ears-system/frontend/client/index.html'],
    ['/review', '/ears-system/frontend/review/index.html'],
    ['/review/', '/ears-system/frontend/review/index.html'],
  ]);
  const redirectTarget = redirectPaths.get(pathname);
  if (redirectTarget) {
    res.writeHead(302, { Location: redirectTarget, 'Cache-Control': 'no-store' });
    res.end();
    return;
  }

  if (pathname.startsWith('/api/') || pathname.endsWith('.php')) {
    send(res, 404, JSON.stringify({ status: 'error', message: 'API is not served by the static web application.' }), 'application/json; charset=utf-8');
    return;
  }

  const decodedPath = decodeURIComponent(pathname);
  if (decodedPath.split('/').some((part) => part.startsWith('.') || part === '..')) {
    send(res, 404, 'Not Found');
    return;
  }

  const filePath = await resolvePublicFile(decodedPath);
  if (!filePath) {
    send(res, 404, 'Not Found');
    return;
  }

  try {
    const body = req.method === 'HEAD' ? null : await fs.readFile(filePath);
    const stat = await fs.stat(filePath);
    const ext = path.extname(filePath).toLowerCase();
    res.writeHead(200, {
      'Content-Type': mimeTypes.get(ext) ?? 'application/octet-stream',
      'Content-Length': stat.size,
      'Cache-Control': ext === '.html' ? 'no-cache' : 'public, max-age=3600',
    });
    res.end(body);
  } catch {
    send(res, 500, 'Internal Server Error');
  }
});

server.listen(port, host, () => {
  console.log(`EARS static web server listening on ${host}:${port}`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    server.close(() => process.exit(0));
  });
}
