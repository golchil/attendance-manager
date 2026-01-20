<x-filament-panels::page>
    {{-- Employee Selector --}}
    <div class="mb-6">
        {{ $this->form }}
    </div>

    @php
        $user = $this->getSelectedUser();
        $yearlySummary = $user ? $this->getYearlySummary() : [];
        $leaveDetails = $user ? $this->getLeaveDetails() : collect();
        $currentBalance = $user ? $this->getCurrentBalance() : ['total_granted' => 0, 'total_used' => 0, 'total_remaining' => 0];
    @endphp

    @if($user)
    <div class="space-y-6">
        {{-- Print Button - Opens new window --}}
        <div class="flex gap-2">
            <a
                href="{{ route('leave-ledger.print', ['user' => $user->id]) }}"
                target="_blank"
                class="inline-flex items-center px-4 py-2 bg-primary-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
                <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                印刷 / PDF出力
            </a>
        </div>

        {{-- Title --}}
        <h1 class="text-2xl font-bold text-center text-gray-900 dark:text-white">有給休暇管理簿</h1>

        {{-- Employee Information --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-lg font-semibold mb-4 text-gray-900 dark:text-white border-b pb-2">従業員情報</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <span class="text-sm text-gray-500 dark:text-gray-400">氏名</span>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $user->name }}</p>
                </div>
                <div>
                    <span class="text-sm text-gray-500 dark:text-gray-400">社員番号</span>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $user->employee_code ?? '-' }}</p>
                </div>
                <div>
                    <span class="text-sm text-gray-500 dark:text-gray-400">入社日</span>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $user->joined_at?->format('Y年n月j日') ?? '-' }}</p>
                </div>
                <div>
                    <span class="text-sm text-gray-500 dark:text-gray-400">有給付与基準日</span>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $user->effective_leave_grant_date?->format('n月j日') ?? '-' }}</p>
                </div>
            </div>

            {{-- Current Balance Summary --}}
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">
                <div class="flex justify-center">
                    <div class="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-lg min-w-48">
                        <span class="text-sm text-green-600 dark:text-green-400">現在の残日数</span>
                        <p class="text-3xl font-bold text-green-700 dark:text-green-300">{{ $currentBalance['total_remaining'] }}日</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">（上限40日）</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Yearly Summary --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-lg font-semibold mb-4 text-gray-900 dark:text-white border-b pb-2">年度別サマリー（過去5年間）</h2>

            @if(count($yearlySummary) > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">年度</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">期間</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">繰越</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">付与</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">消化</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">残日数</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($yearlySummary as $summary)
                        <tr class="{{ $loop->first ? 'bg-yellow-50 dark:bg-yellow-900/10' : '' }}">
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                {{ $summary['fiscal_year'] }}
                                @if($loop->first)
                                <span class="ml-1 text-xs text-yellow-600 dark:text-yellow-400">（現在）</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                {{ $summary['period_start']->format('Y/n/j') }} 〜 {{ $summary['period_end']->format('Y/n/j') }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                {{ number_format($summary['carryover'], 1) }}日
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                {{ number_format($summary['granted'], 1) }}日
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                {{ number_format($summary['usage'], 1) }}日
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-semibold {{ $summary['remaining'] <= 5 ? 'text-orange-600 dark:text-orange-400' : 'text-green-600 dark:text-green-400' }}">
                                {{ number_format($summary['remaining'], 1) }}日
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <p class="text-gray-500 dark:text-gray-400 text-center py-4">付与基準日が設定されていないため、年度別サマリーを表示できません。</p>
            @endif
        </div>

        {{-- Leave Details --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-lg font-semibold mb-4 text-gray-900 dark:text-white border-b pb-2">取得日一覧（過去5年間）</h2>

            @if($leaveDetails->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">取得日</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">種別</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">日数</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">備考</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($leaveDetails as $detail)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                {{ $detail['date']->format('Y/n/j') }}
                                <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">
                                    ({{ ['日', '月', '火', '水', '木', '金', '土'][$detail['date']->dayOfWeek] }})
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    {{ $detail['type'] === '全休' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' :
                                       ($detail['type'] === '午前半休' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                       'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200') }}">
                                    {{ $detail['type'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                {{ number_format($detail['days'], 1) }}日
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                {{ $detail['note'] ?? '-' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <td colspan="2" class="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white text-right">合計</td>
                            <td class="px-4 py-3 text-sm font-bold text-right text-gray-900 dark:text-white">
                                {{ number_format($leaveDetails->sum('days'), 1) }}日
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @else
            <p class="text-gray-500 dark:text-gray-400 text-center py-4">取得履歴がありません。</p>
            @endif
        </div>
    </div>
    @else
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-8 text-center">
        <x-heroicon-o-document-text class="w-16 h-16 mx-auto text-gray-400 mb-4" />
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">従業員を選択してください</h3>
        <p class="text-gray-500 dark:text-gray-400">上のセレクトボックスから従業員を選択すると、有給休暇管理簿が表示されます。</p>
    </div>
    @endif
</x-filament-panels::page>
