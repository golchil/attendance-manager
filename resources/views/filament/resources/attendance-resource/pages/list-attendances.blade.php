<x-filament-panels::page>
    {{-- フィルターバー --}}
    <div class="mb-6 p-4 bg-white rounded-lg shadow border border-gray-200">
        <div class="flex flex-wrap items-center gap-4">
            {{-- 従業員選択 --}}
            @php
                $warningSummary = $this->getUserWarningSummary();
                $usersWithWarnings = collect($warningSummary)->filter(fn($s) => $s['total'] > 0)->count();
            @endphp
            <div class="flex items-center gap-2">
                <label for="user-select" class="text-sm font-medium whitespace-nowrap" style="color: #374151;">従業員:</label>
                <select
                    id="user-select"
                    wire:model.live="selectedUserId"
                    class="min-w-[200px] rounded border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    style="background-color: white; color: #111;"
                >
                    <option value="">-- 選択してください --</option>
                    @foreach($this->getUsers() as $user)
                        @php
                            $userWarning = $warningSummary[$user->id] ?? null;
                            $warningLabel = '';
                            if ($userWarning && $userWarning['total'] > 0) {
                                if ($userWarning['warning'] > 0) {
                                    $warningLabel = ' (⚠️' . $userWarning['total'] . ')';
                                } else {
                                    $warningLabel = ' (ℹ️' . $userWarning['total'] . ')';
                                }
                            }
                        @endphp
                        <option value="{{ $user->id }}">
                            {{ $user->employee_code ? $user->employee_code . ' - ' : '' }}{{ $user->name }}{{ $warningLabel }}
                        </option>
                    @endforeach
                </select>
                @if($usersWithWarnings > 0)
                    <span class="text-sm px-2 py-0.5 rounded" style="background-color: #fef3c7; color: #92400e;">
                        警告あり: {{ $usersWithWarnings }}名
                    </span>
                @endif
            </div>

            {{-- 年月選択 --}}
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium whitespace-nowrap" style="color: #374151;">対象月:</label>
                <select
                    wire:model.live="selectedYear"
                    class="rounded border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    style="background-color: white; color: #111;"
                >
                    @for($y = now()->year + 1; $y >= now()->year - 3; $y--)
                        <option value="{{ $y }}">{{ $y }}年</option>
                    @endfor
                </select>
                <select
                    wire:model.live="selectedMonth"
                    class="rounded border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    style="background-color: white; color: #111;"
                >
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}">{{ $m }}月</option>
                    @endfor
                </select>
                <span class="text-sm" style="color: #4b5563;">({{ $this->getPeriodLabel() }})</span>
            </div>

            {{-- アクションボタン --}}
            <div class="ml-auto flex items-center gap-2">
                @foreach($this->getHeaderActions() as $action)
                    {{ $action }}
                @endforeach
            </div>
        </div>
    </div>

    {{-- 勤怠テーブル --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        @if($selectedUserId)
            @php
                $attendances = $this->getAttendances();
                $selectedUser = $this->getUsers()->firstWhere('id', $selectedUserId);
                $monthlyTotal = $this->getMonthlyTotal();
            @endphp

            {{-- テーブルヘッダータイトル --}}
            <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                <h3 class="text-base font-bold" style="color: #111827;">
                    {{ $this->getPeriodTitle() }}
                    @if($selectedUser)
                        <span class="font-normal ml-2" style="color: #4b5563;">- {{ $selectedUser->name }}</span>
                    @endif
                </h3>
            </div>

            @if($attendances->isEmpty())
                <div class="px-6 py-12 text-center">
                    <svg class="w-12 h-12 mx-auto mb-3" style="color: #d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <p class="text-lg font-medium mb-1" style="color: #374151;">勤怠データがありません</p>
                    <p class="text-sm" style="color: #6b7280;">{{ $selectedUser?->name }} さんの {{ $this->getPeriodTitle() }} の勤怠データは登録されていません</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-100 border-b border-gray-300">
                                <th class="px-3 py-2 text-left text-xs font-bold uppercase" style="color: #374151;">日付</th>
                                <th class="px-3 py-2 text-left text-xs font-bold uppercase" style="color: #374151;">曜日</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #374151;">日種別</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #374151;">出勤</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #374151;">退勤</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #374151;">勤務時間</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #c2410c;">普通残業</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #7c3aed;">深夜</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #2563eb;">所定休日</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #dc2626;">法定休日</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #374151;">不在理由</th>
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase" style="color: #d97706;">警告</th>
                                <th class="px-3 py-2 text-right text-xs font-bold uppercase" style="color: #374151;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($attendances as $attendance)
                                @php
                                    $date = \Carbon\Carbon::parse($attendance->date);
                                    $dayOfWeek = ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek];
                                    $isSunday = $date->dayOfWeek === 0;
                                    $isSaturday = $date->dayOfWeek === 6;
                                    $dayType = $attendance->day_type ?? '00';
                                    $daily = $this->calculateDaily($attendance);

                                    // 異常検知
                                    $warnings = $this->detectAnomalies($attendance, $daily);
                                    $hasWarnings = !empty($warnings);
                                    $hasSevereWarning = collect($warnings)->contains(fn($w) => $w['severity'] === 'warning');

                                    // 行の背景色（警告がある場合は黄色優先）
                                    if ($hasWarnings) {
                                        $rowBgStyle = $hasSevereWarning
                                            ? 'background-color: #fef3c7;'  // 重大警告（濃い黄色）
                                            : 'background-color: #fefce8;'; // 軽微警告（薄い黄色）
                                    } else {
                                        $rowBgStyle = match($dayType) {
                                            '01' => 'background-color: #fef2f2;',  // 法定休日（薄い赤）
                                            '02' => 'background-color: #eff6ff;',  // 所定休日（薄い青）
                                            default => 'background-color: #ffffff;', // 平日（白）
                                        };
                                    }

                                    // 日付・曜日の文字色
                                    $dateTextColor = match(true) {
                                        $dayType === '01' || $isSunday => '#dc2626', // 赤
                                        $dayType === '02' || $isSaturday => '#2563eb', // 青
                                        default => '#111827', // 黒
                                    };
                                @endphp
                                <tr wire:key="attendance-{{ $attendance->id }}" class="border-b border-gray-200 hover:bg-gray-50" style="{{ $rowBgStyle }}">
                                    {{-- 日付 --}}
                                    <td class="px-3 py-2 font-medium" style="color: {{ $dateTextColor }};">
                                        {{ $date->format('m/d') }}
                                    </td>
                                    {{-- 曜日 --}}
                                    <td class="px-3 py-2" style="color: {{ $dateTextColor }};">
                                        {{ $dayOfWeek }}
                                    </td>
                                    {{-- 日種別 --}}
                                    <td class="px-3 py-2 text-center">
                                        @if($dayType === '01')
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-bold border" style="background-color: #fee2e2; color: #991b1b; border-color: #fca5a5;">法定休日</span>
                                        @elseif($dayType === '02')
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-bold border" style="background-color: #dbeafe; color: #1e40af; border-color: #93c5fd;">所定休日</span>
                                        @else
                                            <span style="color: #9ca3af;">-</span>
                                        @endif
                                    </td>
                                    {{-- 出勤時刻（緑系） --}}
                                    <td class="px-3 py-2 text-center font-mono font-medium" style="color: #059669;">
                                        {{ $this->formatTime($attendance->clock_in) }}
                                    </td>
                                    {{-- 退勤時刻（青系） --}}
                                    <td class="px-3 py-2 text-center font-mono font-medium" style="color: #2563eb;">
                                        {{ $this->formatTime($attendance->clock_out) }}
                                    </td>
                                    {{-- 勤務時間 --}}
                                    <td class="px-3 py-2 text-center font-mono font-bold" style="color: #111827;">
                                        {{ $this->formatWorkTime($daily['work_minutes']) }}
                                    </td>
                                    {{-- 普通残業（オレンジ） --}}
                                    <td class="px-3 py-2 text-center font-mono" style="color: {{ $daily['overtime_minutes'] > 0 ? '#ea580c' : '#9ca3af' }}; font-weight: {{ $daily['overtime_minutes'] > 0 ? '600' : '400' }};">
                                        {{ $this->formatWorkTime($daily['overtime_minutes']) }}
                                    </td>
                                    {{-- 深夜残業（紫） --}}
                                    <td class="px-3 py-2 text-center font-mono" style="color: {{ $daily['night_minutes'] > 0 ? '#7c3aed' : '#9ca3af' }}; font-weight: {{ $daily['night_minutes'] > 0 ? '600' : '400' }};">
                                        {{ $this->formatWorkTime($daily['night_minutes']) }}
                                    </td>
                                    {{-- 所定休日労働（青） --}}
                                    <td class="px-3 py-2 text-center font-mono" style="color: {{ $daily['prescribed_holiday_minutes'] > 0 ? '#2563eb' : '#9ca3af' }}; font-weight: {{ $daily['prescribed_holiday_minutes'] > 0 ? '600' : '400' }};">
                                        {{ $this->formatWorkTime($daily['prescribed_holiday_minutes']) }}
                                    </td>
                                    {{-- 法定休日労働（赤） --}}
                                    <td class="px-3 py-2 text-center font-mono" style="color: {{ $daily['holiday_minutes'] > 0 ? '#dc2626' : '#9ca3af' }}; font-weight: {{ $daily['holiday_minutes'] > 0 ? '600' : '400' }};">
                                        {{ $this->formatWorkTime($daily['holiday_minutes']) }}
                                    </td>
                                    {{-- 不在理由 --}}
                                    <td class="px-3 py-2 text-center">
                                        @if($attendance->absence_reason)
                                            @php
                                                $badgeStyle = match($attendance->absence_reason) {
                                                    'paid_leave' => 'background-color: #dcfce7; color: #166534; border-color: #86efac;', // 緑
                                                    'am_half_leave', 'pm_half_leave' => 'background-color: #fef9c3; color: #854d0e; border-color: #fde047;', // 黄
                                                    'absence' => 'background-color: #fee2e2; color: #991b1b; border-color: #fca5a5;', // 赤
                                                    default => 'background-color: #f3f4f6; color: #374151; border-color: #d1d5db;', // グレー
                                                };
                                            @endphp
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium border" style="{{ $badgeStyle }}">
                                                {{ $this->getAbsenceReasonLabel($attendance->absence_reason) }}
                                            </span>
                                        @else
                                            <span style="color: #9ca3af;">-</span>
                                        @endif
                                    </td>
                                    {{-- 警告 --}}
                                    <td class="px-3 py-2 text-center">
                                        @if($hasWarnings)
                                            <div class="flex items-center justify-center gap-1">
                                                @foreach($warnings as $warning)
                                                    <span
                                                        class="cursor-help text-base"
                                                        title="{{ $warning['message'] }}"
                                                        style="color: {{ $warning['severity'] === 'warning' ? '#d97706' : '#6b7280' }};"
                                                    >{{ $warning['icon'] }}</span>
                                                @endforeach
                                            </div>
                                            <div class="text-xs mt-0.5 whitespace-nowrap" style="color: #92400e;">
                                                {{ collect($warnings)->pluck('message')->join(', ') }}
                                            </div>
                                        @else
                                            <span style="color: #9ca3af;">-</span>
                                        @endif
                                    </td>
                                    {{-- 操作 --}}
                                    <td class="px-3 py-2 text-right">
                                        <a href="{{ \App\Filament\Resources\AttendanceResource::getUrl('edit', ['record' => $attendance]) }}"
                                           class="inline-flex items-center justify-center w-7 h-7 rounded hover:bg-blue-100 transition-colors" style="color: #2563eb;">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path>
                                            </svg>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        {{-- フッター（合計行） --}}
                        <tfoot>
                            <tr class="bg-gray-100 border-t-2 border-gray-400 font-bold">
                                <td colspan="5" class="px-3 py-3 text-right" style="color: #111827;">
                                    月度合計（出勤 {{ $monthlyTotal['work_days'] }} 日）
                                </td>
                                <td class="px-3 py-3 text-center font-mono" style="color: #111827;">
                                    {{ $this->formatWorkTime($monthlyTotal['work_minutes']) }}
                                </td>
                                <td class="px-3 py-3 text-center font-mono" style="color: #ea580c;">
                                    {{ $this->formatWorkTime($monthlyTotal['overtime_minutes']) }}
                                </td>
                                <td class="px-3 py-3 text-center font-mono" style="color: #7c3aed;">
                                    {{ $this->formatWorkTime($monthlyTotal['night_minutes']) }}
                                </td>
                                <td class="px-3 py-3 text-center font-mono" style="color: #2563eb;">
                                    {{ $this->formatWorkTime($monthlyTotal['prescribed_holiday_minutes']) }}
                                </td>
                                <td class="px-3 py-3 text-center font-mono" style="color: #dc2626;">
                                    {{ $this->formatWorkTime($monthlyTotal['holiday_minutes']) }}
                                </td>
                                <td colspan="3"></td>
                            </tr>
                            @if($monthlyTotal['overtime_over60_minutes'] > 0 || $monthlyTotal['night_over60_minutes'] > 0)
                            <tr style="background-color: #fff7ed; border-top: 1px solid #fed7aa;">
                                <td colspan="5" class="px-3 py-2 text-right text-sm" style="color: #c2410c;">
                                    60時間超過分
                                </td>
                                <td class="px-3 py-2 text-center" style="color: #9ca3af;">-</td>
                                <td class="px-3 py-2 text-center font-mono font-semibold" style="color: #c2410c;">
                                    {{ $this->formatWorkTime($monthlyTotal['overtime_over60_minutes']) }}
                                </td>
                                <td class="px-3 py-2 text-center font-mono font-semibold" style="color: #c2410c;">
                                    {{ $this->formatWorkTime($monthlyTotal['night_over60_minutes']) }}
                                </td>
                                <td colspan="5"></td>
                            </tr>
                            @endif
                        </tfoot>
                    </table>
                </div>

                {{-- サマリーカード --}}
                <div class="px-4 py-4 border-t border-gray-200" style="background-color: #f9fafb;">
                    {{-- 上段: 各種労働時間 --}}
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
                        {{-- 所定労働 --}}
                        <div class="text-center p-3 rounded-lg border shadow-sm" style="background-color: #ffffff; border-color: #d1d5db;">
                            <div class="text-xs font-medium mb-1" style="color: #4b5563;">所定労働</div>
                            <div class="text-lg font-bold font-mono" style="color: #111827;">{{ $this->formatWorkTime($monthlyTotal['regular_minutes']) }}</div>
                        </div>
                        {{-- 普通残業 --}}
                        <div class="text-center p-3 rounded-lg border shadow-sm" style="background-color: #ffffff; border-color: #fdba74;">
                            <div class="text-xs font-medium mb-1" style="color: #ea580c;">普通残業</div>
                            <div class="text-lg font-bold font-mono" style="color: #ea580c;">{{ $this->formatWorkTime($monthlyTotal['overtime_minutes']) }}</div>
                            @if($monthlyTotal['overtime_over60_minutes'] > 0)
                                <div class="text-xs mt-1" style="color: #c2410c;">+{{ $this->formatWorkTime($monthlyTotal['overtime_over60_minutes']) }} (60h超)</div>
                            @endif
                        </div>
                        {{-- 深夜残業 --}}
                        <div class="text-center p-3 rounded-lg border shadow-sm" style="background-color: #ffffff; border-color: #c4b5fd;">
                            <div class="text-xs font-medium mb-1" style="color: #7c3aed;">深夜残業</div>
                            <div class="text-lg font-bold font-mono" style="color: #7c3aed;">{{ $this->formatWorkTime($monthlyTotal['night_minutes']) }}</div>
                            @if($monthlyTotal['night_over60_minutes'] > 0)
                                <div class="text-xs mt-1" style="color: #c2410c;">+{{ $this->formatWorkTime($monthlyTotal['night_over60_minutes']) }} (60h超)</div>
                            @endif
                        </div>
                        {{-- 所定休日労働 --}}
                        <div class="text-center p-3 rounded-lg border shadow-sm" style="background-color: #ffffff; border-color: #93c5fd;">
                            <div class="text-xs font-medium mb-1" style="color: #2563eb;">所定休日</div>
                            <div class="text-lg font-bold font-mono" style="color: #2563eb;">{{ $this->formatWorkTime($monthlyTotal['prescribed_holiday_minutes']) }}</div>
                        </div>
                        {{-- 法定休日労働 --}}
                        <div class="text-center p-3 rounded-lg border shadow-sm" style="background-color: #ffffff; border-color: #fca5a5;">
                            <div class="text-xs font-medium mb-1" style="color: #dc2626;">法定休日</div>
                            <div class="text-lg font-bold font-mono" style="color: #dc2626;">{{ $this->formatWorkTime($monthlyTotal['holiday_minutes']) }}</div>
                            <div class="text-xs mt-1" style="color: #6b7280;">（36協定対象外）</div>
                        </div>
                        {{-- 出勤日数 --}}
                        <div class="text-center p-3 rounded-lg border shadow-sm" style="background-color: #ffffff; border-color: #d1d5db;">
                            <div class="text-xs font-medium mb-1" style="color: #4b5563;">出勤日数</div>
                            <div class="text-lg font-bold" style="color: #111827;">{{ $monthlyTotal['work_days'] }}日</div>
                        </div>
                    </div>

                    {{-- 下段: 36協定対象時間（目立つ表示） --}}
                    @php
                        $article36Hours = floor($monthlyTotal['article36_minutes'] / 60);
                        $article36Mins = $monthlyTotal['article36_minutes'] % 60;
                        $over60Total = $monthlyTotal['overtime_over60_minutes'] + $monthlyTotal['night_over60_minutes'];
                        $isOver45 = $monthlyTotal['article36_minutes'] > (45 * 60);  // 45時間超
                        $isOver60 = $monthlyTotal['article36_minutes'] > (60 * 60);  // 60時間超
                    @endphp
                    <div class="p-4 rounded-lg border-2 {{ $isOver60 ? 'border-red-500 bg-red-50' : ($isOver45 ? 'border-orange-400 bg-orange-50' : 'border-emerald-400 bg-emerald-50') }}">
                        <div class="flex items-center justify-between flex-wrap gap-4">
                            <div class="flex items-center gap-3">
                                <div class="text-sm font-bold" style="color: {{ $isOver60 ? '#dc2626' : ($isOver45 ? '#ea580c' : '#059669') }};">
                                    36協定対象時間
                                </div>
                                <div class="text-2xl font-bold font-mono" style="color: {{ $isOver60 ? '#dc2626' : ($isOver45 ? '#ea580c' : '#059669') }};">
                                    {{ $this->formatWorkTime($monthlyTotal['article36_minutes']) }}
                                </div>
                                <div class="text-sm" style="color: #6b7280;">/ 60:00（上限）</div>
                            </div>
                            <div class="flex items-center gap-4 text-xs" style="color: #6b7280;">
                                <span>= 普通残業 + 深夜 + 所定休日</span>
                                @if($over60Total > 0)
                                    <span class="px-2 py-1 rounded font-bold" style="background-color: #fee2e2; color: #dc2626;">
                                        60時間超過: {{ $this->formatWorkTime($over60Total) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        {{-- プログレスバー --}}
                        <div class="mt-3 h-3 rounded-full overflow-hidden" style="background-color: #e5e7eb;">
                            @php
                                $percentage = min(100, ($monthlyTotal['article36_minutes'] / (60 * 60)) * 100);
                                $barColor = $isOver60 ? '#dc2626' : ($isOver45 ? '#f97316' : '#10b981');
                            @endphp
                            <div class="h-full rounded-full transition-all" style="width: {{ $percentage }}%; background-color: {{ $barColor }};"></div>
                        </div>
                        <div class="mt-1 flex justify-between text-xs" style="color: #9ca3af;">
                            <span>0h</span>
                            <span style="color: #f97316;">45h</span>
                            <span style="color: #dc2626;">60h</span>
                        </div>
                    </div>
                </div>
            @endif
        @else
            {{-- 従業員未選択時 --}}
            <div class="px-6 py-12 text-center">
                <svg class="w-12 h-12 mx-auto mb-3" style="color: #d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <p class="text-lg font-medium mb-1" style="color: #374151;">従業員を選択してください</p>
                <p class="text-sm" style="color: #6b7280;">上部のプルダウンから従業員を選択すると、勤怠データが表示されます</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
