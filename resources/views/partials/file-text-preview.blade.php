<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $filename }}</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: #f6f8f4;
            color: #1f3028;
        }
        pre {
            margin: 0;
            font:
                15px/1.65 Consolas,
                monospace;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            tab-size: 4;
        }
    </style>
</head>
<body>
    <pre>{{ $content }}</pre>
</body>
</html>
