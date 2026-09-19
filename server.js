import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = 3000;
const HOST = '0.0.0.0';

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

const server = http.createServer((req, res) => {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  const parsedUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  let pathname = decodeURIComponent(parsedUrl.pathname);

  // Root redirect to the admin panel
  if (pathname === '/' || pathname === '/admin' || pathname === '/admin/') {
    res.writeHead(302, { 'Location': '/ears-system/frontend/admin/index.html' });
    res.end();
    return;
  }

  // Handle mock API endpoints if client requests PHP endpoints
  if (pathname.includes('/api/') || pathname.endsWith('.php')) {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'success', message: 'OK', data: [] }));
    return;
  }

  // Potential local file locations to check
  const candidatePaths = [
    path.join(__dirname, pathname),
    path.join(__dirname, 'ears-system/frontend/admin', pathname),
    path.join(__dirname, 'ears-system/frontend', pathname),
    path.join(__dirname, 'frontend', pathname)
  ];

  let filePathToServe = null;
  for (const candidate of candidatePaths) {
    try {
      if (fs.existsSync(candidate)) {
        const stat = fs.statSync(candidate);
        if (stat.isFile()) {
          filePathToServe = candidate;
          break;
        } else if (stat.isDirectory()) {
          const indexHtml = path.join(candidate, 'index.html');
          if (fs.existsSync(indexHtml)) {
            filePathToServe = indexHtml;
            break;
          }
        }
      }
    } catch {
      // Continue to next candidate
    }
  }

  if (filePathToServe) {
    const ext = path.extname(filePathToServe).toLowerCase();
    const contentType = MIME_TYPES[ext] || 'application/octet-stream';

    fs.readFile(filePathToServe, (err, data) => {
      if (err) {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end('500 Internal Server Error');
        return;
      }
      res.writeHead(200, { 'Content-Type': contentType });
      res.end(data);
    });
  } else {
    // 404 Not Found fallback
    res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`<!DOCTYPE html>
<html>
<head><title>404 Not Found</title></head>
<body style="font-family:sans-serif;text-align:center;padding:50px;">
  <h2>404 - Page Not Found</h2>
  <p><a href="/ears-system/frontend/admin/index.html">Go to EARS Admin Panel</a></p>
</body>
</html>`);
  }
});

server.listen(PORT, HOST, () => {
  console.log(`EARS server listening on http://${HOST}:${PORT}`);
});
