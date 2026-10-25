import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = Number.parseInt(process.env.PORT || '3000', 10);
const HOST = process.env.HOST || '0.0.0.0';

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

function sendJson(res, statusCode, payload) {
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store'
  });
  res.end(JSON.stringify(payload));
}

function isInsideProject(candidatePath) {
  const relativePath = path.relative(__dirname, candidatePath);
  return relativePath === '' ||
    (!relativePath.startsWith(`..${path.sep}`) &&
      relativePath !== '..' &&
      !path.isAbsolute(relativePath));
}

const server = http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  let pathname;
  try {
    pathname = decodeURIComponent(new URL(req.url, `http://${req.headers.host || 'localhost'}`).pathname);
  } catch {
    sendJson(res, 400, {
      status: 'error',
      code: 'INVALID_URL',
      message: 'The requested URL is malformed.'
    });
    return;
  }

  // Convenience redirects for the main application areas.
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

  // This Node server serves static files only. Do not pretend that PHP/API
  // requests succeeded: no database operation is performed by this fallback.
  if (pathname.includes('/api/') || pathname.endsWith('.php')) {
    sendJson(res, 501, {
      status: 'error',
      code: 'API_NOT_CONFIGURED',
      message: 'This endpoint is not connected to a running backend. No data was saved or changed.'
    });
    return;
  }

  const candidatePaths = [
    path.join(__dirname, pathname),
    path.join(__dirname, 'ears-system/frontend/client', pathname),
    path.join(__dirname, 'ears-system/frontend/admin', pathname),
    path.join(__dirname, 'ears-system/frontend', pathname),
    path.join(__dirname, 'frontend', pathname)
  ];

  let filePathToServe = null;
  for (const candidate of candidatePaths) {
    const resolvedCandidate = path.resolve(candidate);

    // Reject traversal attempts before checking or reading files.
    if (!isInsideProject(resolvedCandidate)) {
      continue;
    }

    try {
      if (!fs.existsSync(resolvedCandidate)) {
        continue;
      }

      const stat = fs.statSync(resolvedCandidate);
      if (stat.isFile()) {
        filePathToServe = resolvedCandidate;
        break;
      }

      if (stat.isDirectory()) {
        const indexHtml = path.resolve(resolvedCandidate, 'index.html');
        if (isInsideProject(indexHtml) && fs.existsSync(indexHtml) && fs.statSync(indexHtml).isFile()) {
          filePathToServe = indexHtml;
          break;
        }
      }
    } catch {
      // Continue searching the remaining known frontend locations.
    }
  }

  if (!filePathToServe) {
    res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>404 Not Found</title></head>
<body style="font-family:sans-serif;text-align:center;padding:50px;">
  <h2>404 - Page Not Found</h2>
  <p>The requested page could not be found.</p>
  <p><a href="/ears-system/frontend/admin/index.html">Go to EARS Admin Panel</a></p>
</body>
</html>`);
    return;
  }

  const ext = path.extname(filePathToServe).toLowerCase();
  const contentType = MIME_TYPES[ext] || 'application/octet-stream';

  fs.readFile(filePathToServe, (err, data) => {
    if (err) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('500 Internal Server Error');
      return;
    }

    res.writeHead(200, { 'Content-Type': contentType, 'X-Content-Type-Options': 'nosniff' });
    res.end(data);
  });
});

server.listen(PORT, HOST, () => {
  console.log(`EARS server listening on http://${HOST}:${PORT}`);
});
