<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #222; }
        h1 { font-size: 20px; margin-bottom: 4px; color: #8c1d2d; }
        .meta { color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
        th { background: #f5f5f5; width: 45%; }
        .section-title { font-weight: bold; margin-top: 16px; margin-bottom: 6px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">Report date: {{ $date }} &nbsp;|&nbsp; Generated: {{ $generatedAt }}</p>

    @php
        $scalarRows = collect($data)->reject(fn ($value) => is_array($value) || $value instanceof \Illuminate\Support\Collection);
        $listSections = collect($data)->filter(fn ($value) => is_array($value) || $value instanceof \Illuminate\Support\Collection);
    @endphp

    @if ($scalarRows->isNotEmpty())
        <table>
            @foreach ($scalarRows as $label => $value)
                <tr>
                    <th>{{ \Illuminate\Support\Str::of($label)->replace('_', ' ')->title() }}</th>
                    <td>{{ $value === null ? '—' : (is_bool($value) ? ($value ? 'Yes' : 'No') : $value) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @foreach ($listSections as $sectionLabel => $rows)
        @php
            // $rows is the section itself (e.g. waiting-time's "departments"),
            // a Collection whose ELEMENTS are already plain arrays — casting
            // the Collection object itself with (array) exposes its internal
            // protected properties instead of converting it, so it's never
            // cast directly; only ->toArray()/collect() on it, or the plain
            // per-row arrays inside it.
            $rowsArray = collect($rows)->map(fn ($row) => (array) $row)->values();
        @endphp
        <div class="section-title">{{ \Illuminate\Support\Str::of($sectionLabel)->replace('_', ' ')->title() }}</div>
        @if ($rowsArray->isEmpty())
            <p>No data.</p>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach (array_keys($rowsArray->first()) as $column)
                            <th style="width:auto;">{{ \Illuminate\Support\Str::of($column)->replace('_', ' ')->title() }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rowsArray as $row)
                        <tr>
                            @foreach ($row as $value)
                                <td>{{ $value === null ? '—' : (is_bool($value) ? ($value ? 'Yes' : 'No') : $value) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
