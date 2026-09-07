<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suggest a Feature ù Laravel</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #1e293b;
            --muted: #64748b;
            --line: #e2e8f0;
            --blue: #3b82f6;
            --blue-dark: #2563eb;
            --bg: #f8fafc;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 40px 16px;
        }
        .box {
            background: #fff;
            padding: 30px;
            max-width: 560px;
            width: 100%;
            text-align: center;
            border: 1px solid var(--line);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08);
        }
        .engine {
            display: inline-block;
            margin-bottom: 12px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #5b21b6;
            background: #f3e8ff;
            border: 1px solid #e9d5ff;
        }
        h2 { color: var(--ink); margin: 0 0 8px; font-size: 22px; }
        .lead { color: var(--muted); font-size: 14px; margin: 0 0 18px; }
        textarea {
            width: 100%;
            padding: 10px 12px;
            background: var(--bg);
            color: #334155;
            border: 1px solid #cbd5e1;
            margin: 8px 0 12px;
            font-family: inherit;
            resize: vertical;
            min-height: 96px;
            outline: none;
        }
        textarea:focus { border-color: var(--blue); }
        .row { display: flex; justify-content: space-between; margin-bottom: 15px; gap: 8px; }
        .btn {
            background: var(--blue);
            color: #fff;
            border: none;
            padding: 10px 20px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }
        .btn:hover { background: var(--blue-dark); }
        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
            border: none;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-block { width: 100%; }
        .back-link {
            display: block;
            margin-top: 20px;
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        .back-link:hover { color: var(--blue); }
        .msg { padding: 8px; margin-bottom: 16px; font-size: 13px; text-align: left; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .feed { margin-top: 36px; text-align: left; border-top: 1px solid var(--line); padding-top: 20px; }
        .feed h3 { font-size: 16px; margin: 0 0 15px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px; border: 1px solid var(--line); vertical-align: top; }
        thead tr { background: #f1f5f9; text-align: left; }
        .pill {
            display: inline-block;
            padding: 2px 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .pill-pending { background: #fef9c3; color: #854d0e; }
        .pill-done { background: #dcfce7; color: #166534; }
        .pill-bad { background: #fee2e2; color: #991b1b; }
        .empty { padding: 15px; text-align: center; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="box">
        <span class="engine">{{ $engine }}</span>
        <h2>Suggest a Feature</h2>
        <p class="lead">Help us improve the system. Submissions are reviewed directly by the Developer.</p>

        @if (!empty($success))
            <div class="msg success">{{ $success }}</div>
        @endif
        @if (!empty($error))
            <div class="msg error">{{ $error }}</div>
        @endif
        @if ($errors->any())
            <div class="msg error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ $formAction !== '' ? $formAction : '' }}">
            @csrf
            <textarea name="suggestion" id="suggestionBox" rows="3" placeholder="1. Suggestion one..." required>{{ old('suggestion') }}</textarea>
            <div class="row">
                <button type="button" id="addLineBtn" class="btn-secondary">+ Add another line</button>
                <div style="flex:1;"></div>
            </div>
            <button type="submit" class="btn btn-block">Submit to Developer</button>
        </form>

        <a href="{{ $backUrl }}" class="back-link">? Return to Dashboard</a>

        <div class="feed">
            <h3>Recent Suggestions</h3>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Suggestion</th>
                            <th>By</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($suggestions as $s)
                            <tr>
                                <td>{!! nl2br(e($s->suggestion)) !!}</td>
                                <td style="white-space:nowrap;color:#64748b;">{{ $s->user->full_name ?? 'ù' }}</td>
                                <td style="text-align:center;">
                                    @if ($s->status === 'pending')
                                        <span class="pill pill-pending">Pending</span>
                                    @elseif ($s->status === 'accomplished')
                                        <span class="pill pill-done">Done</span>
                                    @else
                                        <span class="pill pill-bad">Impossible</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="empty">No suggestions yet. Be the first!</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const textarea = document.getElementById('suggestionBox');
        const addLineBtn = document.getElementById('addLineBtn');
        textarea.addEventListener('focus', function () {
            if (this.value.trim() === '') this.value = '1. ';
        });
        addLineBtn.addEventListener('click', function () {
            const currentVal = textarea.value;
            const matches = currentVal.match(/^(\d+)\.\s/gm);
            let nextNum = 1;
            if (matches && matches.length > 0) {
                nextNum = parseInt(matches[matches.length - 1].match(/\d+/)[0], 10) + 1;
            }
            textarea.value += (currentVal.trim() === '' ? '' : '\n') + nextNum + '. ';
            textarea.focus();
            textarea.scrollTop = textarea.scrollHeight;
        });
    </script>
</body>
</html>
