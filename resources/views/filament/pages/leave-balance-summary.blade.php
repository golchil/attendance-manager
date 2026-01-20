<x-filament-panels::page>
    {{-- Import Result Modal --}}
    @if($showImportResult)
    <div class="mb-6">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">インポート結果</h3>
                <button
                    wire:click="closeImportResult"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                >
                    <x-heroicon-o-x-mark class="w-5 h-5" />
                </button>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                    <div class="text-sm text-green-600 dark:text-green-400">成功</div>
                    <div class="text-2xl font-bold text-green-700 dark:text-green-300">{{ $importResult['imported'] ?? 0 }}件</div>
                </div>
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                    <div class="text-sm text-red-600 dark:text-red-400">エラー</div>
                    <div class="text-2xl font-bold text-red-700 dark:text-red-300">{{ $importResult['skipped'] ?? 0 }}件</div>
                </div>
            </div>

            @if(!empty($importResult['errors']))
            <div class="mt-4">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">エラー詳細</h4>
                <div class="max-h-60 overflow-y-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">行</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">データ</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">エラー内容</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($importResult['errors'] as $error)
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $error['line'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $error['data'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-sm text-red-600 dark:text-red-400">{{ $error['message'] ?? '-' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
