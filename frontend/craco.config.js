// craco.config.js
const path = require("path");
require("dotenv").config();

// Check if we're in development/preview mode (not production build)
// Craco sets NODE_ENV=development for start, NODE_ENV=production for build
const isDevServer = process.env.NODE_ENV !== "production";

// Environment variable overrides
const config = {
  enableHealthCheck: process.env.ENABLE_HEALTH_CHECK === "true",
};

// Conditionally load health check modules only if enabled
let WebpackHealthPlugin;
let setupHealthEndpoints;
let healthPluginInstance;

if (config.enableHealthCheck) {
  WebpackHealthPlugin = require("./plugins/health-check/webpack-health-plugin");
  setupHealthEndpoints = require("./plugins/health-check/health-endpoints");
  healthPluginInstance = new WebpackHealthPlugin();
}

let webpackConfig = {
  eslint: {
    configure: {
      extends: ["plugin:react-hooks/recommended"],
      rules: {
        "react-hooks/rules-of-hooks": "error",
        "react-hooks/exhaustive-deps": "warn",
      },
    },
  },
  webpack: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
    configure: (webpackConfig) => {

      // Add ignored patterns to reduce watched directories
        webpackConfig.watchOptions = {
          ...webpackConfig.watchOptions,
          ignored: [
            '**/node_modules/**',
            '**/.git/**',
            '**/build/**',
            '**/dist/**',
            '**/coverage/**',
            '**/public/**',
        ],
      };

      // Add health check plugin to webpack if enabled
      if (config.enableHealthCheck && healthPluginInstance) {
        webpackConfig.plugins.push(healthPluginInstance);
      }
      return webpackConfig;
    },
  },
};

webpackConfig.devServer = (devServerConfig) => {
  // ── Proxy PHP / Aether routes to Apache (port 8001) ─────────────────────
  // Without this, CRA's SPA-fallback serves index.html for /aetherV2/*
  // and the PHP-served Aether dashboard never reaches the user.
  // We mount the proxy at the START of the middleware chain via unshift so
  // it runs before any other middleware (history-api-fallback etc).
  const originalSetupMiddlewares = devServerConfig.setupMiddlewares;
  devServerConfig.setupMiddlewares = (middlewares, devServer) => {
    if (originalSetupMiddlewares) {
      middlewares = originalSetupMiddlewares(middlewares, devServer);
    }

    const { createProxyMiddleware } = require('http-proxy-middleware');
    const phpProxy = createProxyMiddleware({
      target: 'http://127.0.0.1:8001',
      changeOrigin: true,
      secure: false,
      ws: false,
      logLevel: 'silent',
      onProxyReq: (proxyReq, req) => {
        // Preserve the full URL the browser asked for
        proxyReq.path = req.originalUrl || req.url;
      },
    });

    middlewares.unshift({
      name: 'aether-php-proxy',
      path: '/aetherV2',
      middleware: phpProxy,
    });
    middlewares.unshift({
      name: 'aether-v1-proxy',
      path: '/aether',
      middleware: phpProxy,
    });
    middlewares.unshift({
      name: 'aether-uploads-proxy',
      path: '/uploads',
      middleware: phpProxy,
    });

    // Health endpoints (optional)
    if (config.enableHealthCheck && setupHealthEndpoints && healthPluginInstance) {
      setupHealthEndpoints(devServer, healthPluginInstance);
    }
    return middlewares;
  };

  return devServerConfig;
};

// Wrap with visual edits (automatically adds babel plugin, dev server, and overlay in dev mode)
if (isDevServer) {
  try {
    const { withVisualEdits } = require("@emergentbase/visual-edits/craco");
    webpackConfig = withVisualEdits(webpackConfig);

    // ── Re-wrap devServer AFTER visual-edits to inject the PHP proxy.
    //    visual-edits replaces setupMiddlewares with its own; we must chain
    //    on top so our proxy is the FIRST middleware to see each request.
    const innerDevServer = webpackConfig.devServer;
    webpackConfig.devServer = (devServerConfig) => {
      const out = innerDevServer ? innerDevServer(devServerConfig) : devServerConfig;
      const innerSetup = out.setupMiddlewares;
      out.setupMiddlewares = (middlewares, devServer) => {
        if (typeof innerSetup === "function") {
          middlewares = innerSetup(middlewares, devServer);
        }
        // Native http forwarder — avoids body-loss issues with http-proxy-middleware
        const http = require("http");
        const forward = (req, res) => {
          const opts = {
            host: "127.0.0.1",
            port: 8001,
            method: req.method,
            path: req.originalUrl || req.url,
            headers: { ...req.headers, host: "127.0.0.1:8001" },
          };
          const proxied = http.request(opts, (apacheRes) => {
            res.writeHead(apacheRes.statusCode, apacheRes.headers);
            apacheRes.pipe(res);
          });
          proxied.on("error", (err) => {
            res.statusCode = 502;
            res.end("Aether proxy: " + err.message);
          });
          // If body-parser already consumed req, replay the parsed body
          if (req.body && (Object.keys(req.body).length > 0 || Buffer.isBuffer(req.body))) {
            const ct = req.headers["content-type"] || "";
            let payload = "";
            if (Buffer.isBuffer(req.body)) {
              payload = req.body;
            } else if (ct.includes("application/json")) {
              payload = JSON.stringify(req.body);
            } else if (ct.includes("application/x-www-form-urlencoded")) {
              payload = new URLSearchParams(req.body).toString();
            } else {
              payload = String(req.body);
            }
            proxied.setHeader("Content-Length", Buffer.byteLength(payload));
            proxied.end(payload);
          } else {
            // Stream raw body
            req.pipe(proxied);
          }
        };

        devServer.app.use((req, res, next) => {
          if (
            req.url.startsWith("/aetherV2") ||
            req.url.startsWith("/aether/") ||
            req.url.startsWith("/uploads/") ||
            req.url.startsWith("/api/auth/") ||
            req.url.startsWith("/api/identity")
          ) {
            return forward(req, res);
          }
          next();
        });
        return middlewares;
      };
      return out;
    };
  } catch (err) {
    if (err.code === 'MODULE_NOT_FOUND' && err.message.includes('@emergentbase/visual-edits/craco')) {
      console.warn(
        "[visual-edits] @emergentbase/visual-edits not installed — visual editing disabled."
      );
    } else {
      throw err;
    }
  }
}

module.exports = webpackConfig;
