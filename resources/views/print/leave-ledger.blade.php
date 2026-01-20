<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>有給休暇管理簿 - {{ $user->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            background: #fff;
            color: #000;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        h1 {
            font-size: 24px;
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }

        h2 {
            font-size: 14px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #333;
        }

        .section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #ccc;
        }

        .info-table {
            width: 100%;
            margin-bottom: 15px;
        }

        .info-table td {
            padding: 5px 10px;
            vertical-align: top;
        }

        .info-table .label {
            font-size: 11px;
            color: #666;
        }

        .info-table .value {
            font-weight: bold;
        }

        .balance-box {
            text-align: center;
            padding: 15px;
            margin-top: 15px;
            border-top: 1px solid #ccc;
        }

        .balance-inner {
            display: inline-block;
            padding: 15px 40px;
            border: 2px solid #22c55e;
        }

        .balance-label {
            font-size: 12px;
            color: #16a34a;
        }

        .balance-value {
            font-size: 32px;
            font-weight: bold;
            color: #15803d;
        }

        .balance-note {
            font-size: 11px;
            color: #666;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table.data-table th,
        table.data-table td {
            border: 1px solid #333;
            padding: 8px;
            text-align: left;
        }

        table.data-table th {
            background: #e5e7eb;
            font-weight: bold;
        }

        table.data-table .text-right {
            text-align: right;
        }

        table.data-table .current-row {
            background: #fefce8;
        }

        table.data-table .current-badge {
            font-size: 10px;
            color: #ca8a04;
        }

        table.data-table tfoot td {
            background: #f3f4f6;
            font-weight: bold;
        }

        .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid #666;
            font-size: 11px;
        }

        .text-green { color: #16a34a; }
        .text-orange { color: #ea580c; }
        .text-gray { color: #666; }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #333;
            font-size: 11px;
            color: #666;
        }

        .footer-table {
            width: 100%;
        }

        .footer-table td {
            padding: 0;
        }

        .no-print {
            margin-bottom: 20px;
        }

        .print-btn {
            padding: 10px 20px;
            font-size: 14px;
            background: #2563eb;
            color: #fff;
            border: none;
            cursor: pointer;
        }

        .print-btn:hover {
            background: #1d4ed8;
        }

        .back-link {
            margin-left: 10px;
            color: #2563eb;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                padding: 0;
            }

            .section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="no-print">
            <button class="print-btn" onclick="window.print()">印刷 / PDF出力</button>
            <a href="{{ url()->previous() }}" class="back-link">戻る</a>
        </div>

        <h1>有給休暇管理簿</h1>

        {{-- 従業員情報 --}}
        <div class="section">
            <h2>従業員情報</h2>
            <table class="info-table">
                <tr>
                    <td style="width: 25%;">
                        <div class="label">氏名</div>
                        <div class="value">{{ $user->name }}</div>
                    </td>
                    <td style="width: 25%;">
                        <div class="label">社員番号</div>
                        <div class="value">{{ $user->employee_code ?? '-' }}</div>
                    </td>
                    <td style="width: 25%;">
                        <div class="label">入社日</div>
                        <div class="value">{{ $user->joined_at?->format('Y年n月j日') ?? '-' }}</div>
                    </td>
                    <td style="width: 25%;">
                        <div class="label">有給付与基準日</div>
                        <div class="value">{{ $user->effective_leave_grant_date?->format('n月j日') ?? '-' }}</div>
                    </td>
                </tr>
            </table>

            <div class="balance-box">
                <div class="balance-inner">
                    <div class="balance-label">現在の残日数</div>
                    <div class="balance-value">{{ $currentBalance['total_remaining'] }}日</div>
                    <div class="balance-note">（上限40日）</div>
                </div>
            </div>
        </div>

        {{-- 年度別サマリー --}}
        <div class="section">
            <h2>年度別サマリー（過去5年間）</h2>

            @if(count($yearlySummary) > 0)
            <table class="data-table">
                <thead>
                    <tr>
                        <th>年度</th>
                        <th>期間</th>
                        <th class="text-right">繰越</th>
                        <th class="text-right">付与</th>
                        <th class="text-right">消化</th>
                        <th class="text-right">残日数</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($yearlySummary as $summary)
                    <tr class="{{ $loop->first ? 'current-row' : '' }}">
                        <td>
                            {{ $summary['fiscal_year'] }}
                            @if($loop->first)
                            <span class="current-badge">（現在）</span>
                            @endif
                        </td>
                        <td>{{ $summary['period_start']->format('Y/n/j') }} 〜 {{ $summary['period_end']->format('Y/n/j') }}</td>
                        <td class="text-right">{{ number_format($summary['carryover'], 1) }}日</td>
                        <td class="text-right">{{ number_format($summary['granted'], 1) }}日</td>
                        <td class="text-right">{{ number_format($summary['usage'], 1) }}日</td>
                        <td class="text-right {{ $summary['remaining'] <= 5 ? 'text-orange' : 'text-green' }}" style="font-weight: bold;">
                            {{ number_format($summary['remaining'], 1) }}日
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <p class="text-gray" style="text-align: center; padding: 20px;">
                付与基準日が設定されていないため、年度別サマリーを表示できません。
            </p>
            @endif
        </div>

        {{-- 取得日一覧 --}}
        <div class="section">
            <h2>取得日一覧（過去5年間）</h2>

            @if($leaveDetails->count() > 0)
            <table class="data-table">
                <thead>
                    <tr>
                        <th>取得日</th>
                        <th>種別</th>
                        <th class="text-right">日数</th>
                        <th>備考</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($leaveDetails as $detail)
                    <tr>
                        <td>
                            {{ $detail['date']->format('Y/n/j') }}
                            <span class="text-gray">({{ ['日', '月', '火', '水', '木', '金', '土'][$detail['date']->dayOfWeek] }})</span>
                        </td>
                        <td>
                            <span class="type-badge">{{ $detail['type'] }}</span>
                        </td>
                        <td class="text-right">{{ number_format($detail['days'], 1) }}日</td>
                        <td class="text-gray">{{ $detail['note'] ?? '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-right">合計</td>
                        <td class="text-right">{{ number_format($leaveDetails->sum('days'), 1) }}日</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            @else
            <p class="text-gray" style="text-align: center; padding: 20px;">
                取得履歴がありません。
            </p>
            @endif
        </div>

        {{-- フッター --}}
        <div class="footer">
            <table class="footer-table">
                <tr>
                    <td>出力日: {{ now()->format('Y年n月j日') }}</td>
                    <td style="text-align: right;">労働基準法第39条に基づく年次有給休暇管理簿</td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
