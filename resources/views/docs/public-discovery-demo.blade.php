<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $demo['title'] }} | SHIFT public discovery demo</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #eef2f6;
            --ink: #17202f;
            --muted: #607084;
            --line: #d7dee8;
            --panel: #ffffff;
            --soft: #f7f9fc;
            --teal: #0f766e;
            --indigo: #4f46e5;
            --rose: #be123c;
            --amber: #b45309;
            --accent: var(--teal);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0;
        }

        body {
            overflow: hidden;
            background: var(--bg);
            color: var(--ink);
        }

        .accent-teal { --accent: var(--teal); }
        .accent-indigo { --accent: var(--indigo); }
        .accent-rose { --accent: var(--rose); }
        .accent-amber { --accent: var(--amber); }

        .stage {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100vw;
            height: 100vh;
            padding: 48px;
        }

        .browser {
            width: min(1824px, calc(100vw - 96px));
            height: min(984px, calc(100vh - 96px));
            overflow: hidden;
            border: 1px solid #cfd8e5;
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 32px 80px rgba(23, 32, 47, 0.18);
        }

        .browser-bar {
            display: grid;
            grid-template-columns: 96px 1fr 180px;
            align-items: center;
            gap: 16px;
            height: 58px;
            padding: 0 18px;
            border-bottom: 1px solid var(--line);
            background: #f8fafc;
        }

        .dots {
            display: flex;
            gap: 8px;
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 999px;
        }

        .dot.red { background: #ef4444; }
        .dot.yellow { background: #f59e0b; }
        .dot.green { background: #22c55e; }

        .url {
            overflow: hidden;
            border: 1px solid #d6dee9;
            border-radius: 6px;
            padding: 9px 14px;
            background: #ffffff;
            color: #344156;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
            font-size: 15px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .environment {
            justify-self: end;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            padding: 7px 12px;
            color: #475569;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .content {
            display: grid;
            grid-template-rows: auto 1fr;
            height: calc(100% - 58px);
            background: linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%);
        }

        .hero {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 24px;
            padding: 34px 42px 26px;
            border-bottom: 1px solid var(--line);
        }

        .eyebrow {
            margin: 0 0 9px;
            color: var(--accent);
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1,
        h2,
        h3,
        p {
            margin: 0;
        }

        h1 {
            max-width: 780px;
            font-size: 38px;
            line-height: 1.1;
            letter-spacing: 0;
        }

        .subtitle {
            max-width: 760px;
            margin-top: 12px;
            color: var(--muted);
            font-size: 18px;
            line-height: 1.5;
        }

        .task-chip {
            min-width: 330px;
            align-self: start;
            border: 1px solid var(--line);
            border-left: 5px solid var(--accent);
            border-radius: 8px;
            padding: 18px;
            background: #ffffff;
        }

        .task-chip strong {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--muted);
        }

        .task-chip span {
            display: block;
            font-size: 19px;
            font-weight: 800;
        }

        .screen {
            min-height: 0;
            padding: 28px 42px 38px;
        }

        .grid-two {
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(420px, 0.92fr);
            gap: 24px;
            height: 100%;
        }

        .grid-balanced {
            display: grid;
            grid-template-columns: minmax(0, 0.96fr) minmax(0, 1.04fr);
            gap: 24px;
            height: 100%;
        }

        .panel {
            min-height: 0;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--line);
            background: #fbfcfe;
        }

        .panel-header h2 {
            font-size: 21px;
        }

        .panel-header p {
            margin-top: 5px;
            color: var(--muted);
            font-size: 14px;
        }

        .badge-row,
        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 999px;
            padding: 7px 10px;
            background: #f8fafc;
            color: #344156;
            font-size: 13px;
            font-weight: 700;
        }

        .badge.accent {
            border-color: color-mix(in srgb, var(--accent) 30%, #ffffff);
            background: color-mix(in srgb, var(--accent) 10%, #ffffff);
            color: var(--accent);
        }

        .panel-body {
            padding: 22px;
        }

        .app-shell {
            display: grid;
            grid-template-rows: auto 1fr;
            background: #f5f8fb;
        }

        .app-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
            background: #ffffff;
        }

        .app-brand {
            font-weight: 900;
            letter-spacing: 0;
        }

        .app-nav {
            display: flex;
            gap: 10px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }

        .invoice-page {
            padding: 24px;
        }

        .invoice-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .metric {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            background: #ffffff;
        }

        .metric span {
            display: block;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        .metric strong {
            display: block;
            margin-top: 8px;
            font-size: 24px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #ffffff;
            font-size: 15px;
        }

        .table th,
        .table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            text-align: left;
        }

        .table th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .form-card {
            display: grid;
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 7px;
        }

        .field label {
            color: #475569;
            font-size: 13px;
            font-weight: 800;
        }

        .input,
        .textarea {
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: #ffffff;
            color: var(--ink);
            font-size: 15px;
            line-height: 1.45;
        }

        .input {
            min-height: 42px;
            padding: 10px 12px;
        }

        .textarea {
            min-height: 82px;
            padding: 12px;
        }

        .context-list {
            display: grid;
            gap: 10px;
        }

        .context-item {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 12px;
            align-items: start;
            padding: 11px 0;
            border-bottom: 1px solid #edf1f6;
            font-size: 14px;
        }

        .context-item dt {
            color: var(--muted);
            font-weight: 800;
        }

        .context-item dd {
            margin: 0;
            color: #273449;
            overflow-wrap: anywhere;
        }

        .task-layout .context-item,
        .thread-layout .context-item {
            grid-template-columns: 1fr;
            gap: 6px;
        }

        .primary-button {
            justify-self: start;
            border: 0;
            border-radius: 7px;
            padding: 12px 18px;
            background: var(--accent);
            color: #ffffff;
            font-size: 15px;
            font-weight: 800;
        }

        .portal-shell {
            display: grid;
            grid-template-columns: 260px 1fr;
            height: 100%;
        }

        .sidebar {
            border-right: 1px solid var(--line);
            background: #17202f;
            color: #dbe4f0;
            padding: 24px;
        }

        .sidebar h2 {
            margin-bottom: 28px;
            color: #ffffff;
            font-size: 24px;
        }

        .sidebar-nav {
            display: grid;
            gap: 8px;
        }

        .nav-item {
            border-radius: 7px;
            padding: 12px;
            color: #aebbd0;
            font-weight: 700;
        }

        .nav-item.active {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
        }

        .portal-main {
            min-width: 0;
            padding: 26px;
            background: #f7f9fc;
        }

        .task-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 380px;
            gap: 20px;
            height: 100%;
        }

        .task-detail h2 {
            margin-bottom: 14px;
            font-size: 30px;
            line-height: 1.16;
        }

        .description {
            margin-top: 20px;
            color: #334155;
            font-size: 16px;
            line-height: 1.55;
        }

        .timeline {
            display: grid;
            gap: 13px;
            margin-top: 24px;
        }

        .timeline-item {
            display: grid;
            grid-template-columns: 26px 1fr;
            gap: 12px;
            align-items: start;
            color: #334155;
            font-size: 15px;
            font-weight: 700;
        }

        .timeline-dot {
            width: 13px;
            height: 13px;
            margin-top: 4px;
            border: 3px solid color-mix(in srgb, var(--accent) 22%, #ffffff);
            border-radius: 999px;
            background: var(--accent);
        }

        .error-card {
            border-left: 5px solid var(--rose);
        }

        .error-title {
            color: var(--rose);
            font-size: 20px;
            font-weight: 900;
        }

        .code-block {
            margin-top: 18px;
            border: 1px solid #d4dce8;
            border-radius: 8px;
            background: #101827;
            color: #e5ecf6;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
            font-size: 14px;
            line-height: 1.75;
            padding: 18px;
        }

        .code-block div {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .thread-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 22px;
            height: 100%;
        }

        .messages {
            display: grid;
            gap: 16px;
        }

        .message {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            background: #ffffff;
        }

        .message-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 10px;
        }

        .author {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 999px;
            background: color-mix(in srgb, var(--accent) 18%, #ffffff);
            color: var(--accent);
            font-weight: 900;
        }

        .message p {
            color: #334155;
            font-size: 16px;
            line-height: 1.55;
        }

        .side-stack {
            display: grid;
            gap: 16px;
            align-content: start;
        }

        .mini-card {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            background: #ffffff;
        }

        .mini-card h3 {
            margin-bottom: 12px;
            font-size: 17px;
        }

        .mini-card p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }
    </style>
</head>
<body>
    <main class="stage accent-{{ $demo['accent'] }}" data-screenshot-ready="{{ $demo['slug'] }}">
        <section class="browser" aria-label="SHIFT public discovery demo">
            <div class="browser-bar">
                <div class="dots" aria-hidden="true">
                    <span class="dot red"></span>
                    <span class="dot yellow"></span>
                    <span class="dot green"></span>
                </div>
                <div class="url">{{ $demo['demo_url'] }}</div>
                <div class="environment">Local demo</div>
            </div>

            <div class="content">
                <header class="hero">
                    <div>
                        <p class="eyebrow">SHIFT public discovery demo</p>
                        <h1>{{ $demo['title'] }}</h1>
                        <p class="subtitle">{{ $demo['subtitle'] }}</p>
                    </div>

                    <aside class="task-chip">
                        <strong>{{ $demo['surface'] }}</strong>
                        <span>{{ $demo['task']['id'] }} - {{ $demo['task']['project'] }}</span>
                    </aside>
                </header>

                <section class="screen">
                    @if ($demo['kind'] === 'form')
                        <div class="grid-two">
                            <article class="panel app-shell">
                                <div class="app-topbar">
                                    <div class="app-brand">Northstar Billing</div>
                                    <nav class="app-nav" aria-label="Demo app navigation">
                                        <span>Invoices</span>
                                        <span>Reports</span>
                                        <span>Settings</span>
                                    </nav>
                                </div>

                                <div class="invoice-page">
                                    <div class="invoice-summary">
                                        <div class="metric">
                                            <span>Invoice</span>
                                            <strong>INV-1047</strong>
                                        </div>
                                        <div class="metric">
                                            <span>Status</span>
                                            <strong>Ready</strong>
                                        </div>
                                        <div class="metric">
                                            <span>Total</span>
                                            <strong>GBP 18,420</strong>
                                        </div>
                                    </div>

                                    <table class="table" aria-label="Demo invoice lines">
                                        <thead>
                                            <tr>
                                                <th>Line</th>
                                                <th>Description</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>01</td>
                                                <td>Implementation support</td>
                                                <td>GBP 6,800</td>
                                            </tr>
                                            <tr>
                                                <td>02</td>
                                                <td>Data migration review</td>
                                                <td>GBP 4,100</td>
                                            </tr>
                                            <tr>
                                                <td>03</td>
                                                <td>Finance workflow testing</td>
                                                <td>GBP 7,520</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </article>

                            <article class="panel">
                                <div class="panel-header">
                                    <div>
                                        <h2>Report an issue</h2>
                                        <p>Submitted by {{ $demo['person']['name'] }} from the current app page.</p>
                                    </div>
                                    <span class="badge accent">{{ $demo['task']['priority'] }}</span>
                                </div>
                                <div class="panel-body form-card">
                                    @foreach ($demo['form'] as $field)
                                        <div class="field">
                                            <label>{{ $field['label'] }}</label>
                                            <div class="{{ strlen($field['value']) > 80 ? 'textarea' : 'input' }}">{{ $field['value'] }}</div>
                                        </div>
                                    @endforeach

                                    <div class="badge-row">
                                        <span class="badge">Screenshot attached</span>
                                        <span class="badge">Route captured</span>
                                        <span class="badge">User context included</span>
                                    </div>

                                    <button class="primary-button" type="button">Create SHIFT task</button>
                                </div>
                            </article>
                        </div>
                    @elseif ($demo['kind'] === 'task')
                        <div class="portal-shell panel">
                            <aside class="sidebar">
                                <h2>SHIFT</h2>
                                <nav class="sidebar-nav" aria-label="Demo portal navigation">
                                    <div class="nav-item">Dashboard</div>
                                    <div class="nav-item active">Tasks</div>
                                    <div class="nav-item">Clients</div>
                                    <div class="nav-item">Projects</div>
                                    <div class="nav-item">External users</div>
                                </nav>
                            </aside>

                            <div class="portal-main">
                                <div class="task-layout">
                                    <article class="panel task-detail">
                                        <div class="panel-header">
                                            <div>
                                                <p class="eyebrow">{{ $demo['task']['id'] }}</p>
                                                <h2>{{ $demo['task']['title'] }}</h2>
                                            </div>
                                        </div>
                                        <div class="panel-body">
                                            <div class="meta-row">
                                                <span class="badge accent">{{ $demo['task']['status'] }}</span>
                                                <span class="badge">{{ $demo['task']['priority'] }}</span>
                                                <span class="badge">{{ $demo['task']['source'] }}</span>
                                            </div>
                                            <p class="description">
                                                Maya Thompson created this from the invoice page in the Laravel app.
                                                SHIFT keeps the app URL, route, environment, and reporter context with the task.
                                            </p>
                                            <div class="timeline">
                                                @foreach ($demo['timeline'] as $item)
                                                    <div class="timeline-item">
                                                        <span class="timeline-dot"></span>
                                                        <span>{{ $item }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </article>

                                    <aside class="panel">
                                        <div class="panel-header">
                                            <div>
                                                <h2>App context</h2>
                                                <p>Dummy local data for documentation screenshots.</p>
                                            </div>
                                        </div>
                                        <div class="panel-body">
                                            <dl class="context-list">
                                                @foreach ($demo['context'] as $label => $value)
                                                    <div class="context-item">
                                                        <dt>{{ $label }}</dt>
                                                        <dd>{{ $value }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </div>
                                    </aside>
                                </div>
                            </div>
                        </div>
                    @elseif ($demo['kind'] === 'error')
                        <div class="grid-balanced">
                            <article class="panel error-card">
                                <div class="panel-header">
                                    <div>
                                        <h2>Laravel backend exception</h2>
                                        <p>{{ $demo['error']['occurrences'] }} from {{ $demo['person']['name'] }}'s local session.</p>
                                    </div>
                                    <span class="badge accent">{{ $demo['task']['priority'] }}</span>
                                </div>
                                <div class="panel-body">
                                    <div class="error-title">{{ $demo['error']['class'] }}</div>
                                    <p class="description">{{ $demo['error']['message'] }}</p>
                                    <div class="code-block" aria-label="Demo stack frames">
                                        @foreach ($demo['frames'] as $frame)
                                            <div>{{ $frame }}</div>
                                        @endforeach
                                    </div>
                                </div>
                            </article>

                            <article class="panel">
                                <div class="panel-header">
                                    <div>
                                        <h2>{{ $demo['task']['id'] }} - {{ $demo['task']['title'] }}</h2>
                                        <p>Error intake in SHIFT, with sensitive request fields scrubbed.</p>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <div class="meta-row">
                                        <span class="badge accent">{{ $demo['task']['status'] }}</span>
                                        <span class="badge">{{ $demo['error']['release'] }}</span>
                                    </div>
                                    <dl class="context-list" style="margin-top: 18px;">
                                        @foreach ($demo['context'] as $label => $value)
                                            <div class="context-item">
                                                <dt>{{ $label }}</dt>
                                                <dd>{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    <div class="mini-card" style="margin-top: 18px;">
                                        <h3>Reporter context</h3>
                                        <p>{{ $demo['person']['name'] }} ({{ $demo['person']['email'] }}) was signed in when the backend error occurred.</p>
                                    </div>
                                </div>
                            </article>
                        </div>
                    @else
                        <div class="thread-layout">
                            <article class="panel">
                                <div class="panel-header">
                                    <div>
                                        <p class="eyebrow">{{ $demo['task']['id'] }}</p>
                                        <h2>{{ $demo['task']['title'] }}</h2>
                                        <p>{{ $demo['task']['project'] }}</p>
                                    </div>
                                    <div class="badge-row">
                                        <span class="badge accent">{{ $demo['task']['status'] }}</span>
                                        <span class="badge">{{ $demo['task']['priority'] }}</span>
                                    </div>
                                </div>
                                <div class="panel-body messages">
                                    @foreach ($demo['thread'] as $message)
                                        <div class="message">
                                            <div class="message-head">
                                                <div class="author">
                                                    <span class="avatar">{{ collect(explode(' ', $message['author']))->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}</span>
                                                    <div>
                                                        <strong>{{ $message['author'] }}</strong>
                                                        <div style="color: var(--muted); font-size: 13px;">{{ $message['role'] }}</div>
                                                    </div>
                                                </div>
                                                <span class="badge">{{ $message['time'] }}</span>
                                            </div>
                                            <p>{{ $message['body'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </article>

                            <aside class="side-stack">
                                <div class="mini-card">
                                    <h3>Original context</h3>
                                    <dl class="context-list">
                                        @foreach ($demo['context'] as $label => $value)
                                            <div class="context-item">
                                                <dt>{{ $label }}</dt>
                                                <dd>{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                                <div class="mini-card">
                                    <h3>Workflow fit</h3>
                                    <p>The discussion stays tied to the app page, reporter, and task history instead of being split between email, a ticket, and an error log.</p>
                                </div>
                            </aside>
                        </div>
                    @endif
                </section>
            </div>
        </section>
    </main>
</body>
</html>
