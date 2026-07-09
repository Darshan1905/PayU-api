<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title') — Pavokart</title>
  <style>
    :root {
      --bg: #f0fdf4;
      --card: #ffffff;
      --text: #0f172a;
      --muted: #64748b;
      --border: #e2e8f0;
      --accent: #0f766e;
      --accent-soft: #ccfbf1;
      --danger: #dc2626;
      --danger-soft: #fee2e2;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
      background: var(--page-bg, var(--bg));
      color: var(--text);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
    }
    .card {
      width: 100%;
      max-width: 480px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 40px 32px;
      text-align: center;
      box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08);
    }
    .icon {
      width: 72px;
      height: 72px;
      margin: 0 auto 20px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 36px;
      background: var(--icon-bg, var(--accent-soft));
      color: var(--icon-color, var(--accent));
    }
    h1 {
      margin: 0 0 10px;
      font-size: 26px;
      font-weight: 700;
    }
    .lead {
      margin: 0 0 24px;
      color: var(--muted);
      font-size: 15px;
      line-height: 1.6;
    }
    .details {
      text-align: left;
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 16px;
      margin-bottom: 24px;
      font-size: 14px;
    }
    .row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      padding: 6px 0;
      border-bottom: 1px solid #eef2f7;
    }
    .row:last-child { border-bottom: 0; }
    .label { color: var(--muted); }
    .value {
      font-weight: 600;
      word-break: break-all;
      text-align: right;
    }
    .btn {
      display: inline-block;
      padding: 12px 24px;
      border-radius: 8px;
      font-size: 15px;
      font-weight: 600;
      text-decoration: none;
      background: var(--btn-bg, var(--accent));
      color: #fff;
    }
    .btn:hover { opacity: 0.92; }
    .footer {
      margin-top: 20px;
      font-size: 12px;
      color: var(--muted);
    }
  </style>
</head>
<body>
  @yield('content')
</body>
</html>
