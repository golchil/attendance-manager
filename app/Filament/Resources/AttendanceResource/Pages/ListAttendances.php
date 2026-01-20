<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\User;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceCsvImporter;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ListAttendances extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $resource = AttendanceResource::class;

    protected static string $view = 'filament.resources.attendance-resource.pages.list-attendances';

    public ?int $selectedUserId = null;
    public int $selectedYear;
    public int $selectedMonth;

    protected ?AttendanceCalculator $calculator = null;

    public function mount(): void
    {
        $this->selectedYear = now()->year;
        $this->selectedMonth = now()->month;
        $this->selectedUserId = null;
        $this->calculator = new AttendanceCalculator();
    }

    public function getCalculator(): AttendanceCalculator
    {
        if (!$this->calculator) {
            $this->calculator = new AttendanceCalculator();
        }
        return $this->calculator;
    }

    /**
     * 選択された年月の締め期間を取得
     * 「N月度」= 前月(CLOSING_DAY+1)日〜当月(CLOSING_DAY)日
     */
    public function getPeriod(): array
    {
        $closingDay = config('attendance.closing_day', 20);

        // 当月の締め日（期間終了日）
        $endDate = Carbon::create($this->selectedYear, $this->selectedMonth, $closingDay)->startOfDay();

        // 前月の翌日（期間開始日）= 前月の(CLOSING_DAY + 1)日
        $startDate = $endDate->copy()->subMonth()->addDay();

        return [
            'start' => $startDate,
            'end' => $endDate,
        ];
    }

    /**
     * 期間の表示用文字列を取得
     */
    public function getPeriodLabel(): string
    {
        $period = $this->getPeriod();
        return sprintf(
            '%s〜%s',
            $period['start']->format('n/j'),
            $period['end']->format('n/j')
        );
    }

    /**
     * テーブルヘッダー用のタイトルを取得
     */
    public function getPeriodTitle(): string
    {
        $period = $this->getPeriod();
        return sprintf(
            '%d年%d月度 (%s〜%s)',
            $this->selectedYear,
            $this->selectedMonth,
            $period['start']->format('n/j'),
            $period['end']->format('n/j')
        );
    }

    public function getUsers(): Collection
    {
        return User::where('is_active', true)
            ->orderBy('employee_code')
            ->get(['id', 'name', 'employee_code']);
    }

    public function getAttendances(): Collection
    {
        if (!$this->selectedUserId) {
            return collect();
        }

        $period = $this->getPeriod();

        return Attendance::where('user_id', $this->selectedUserId)
            ->whereBetween('date', [$period['start'], $period['end']])
            ->orderBy('date')
            ->get();
    }

    /**
     * 1日分の残業計算結果を取得
     */
    public function calculateDaily(Attendance $attendance): array
    {
        return $this->getCalculator()->calculateDaily($attendance);
    }

    /**
     * 勤怠データの異常を検知
     */
    public function detectAnomalies(Attendance $attendance, array $dailyCalculation): array
    {
        return $this->getCalculator()->detectAnomalies($attendance, $dailyCalculation);
    }

    /**
     * 月度の残業集計を取得
     */
    public function getMonthlyTotal(): array
    {
        $attendances = $this->getAttendances();
        return $this->getCalculator()->calculateMonthlyTotal($attendances);
    }

    /**
     * 日種別のラベルを取得
     */
    public function getDayTypeLabel(?string $dayType): string
    {
        if (empty($dayType)) {
            return '-';
        }
        $dayTypes = config('attendance.day_types', []);
        return $dayTypes[$dayType]['label'] ?? $dayType;
    }

    /**
     * 不在理由のラベルを取得
     */
    public function getAbsenceReasonLabel(?string $reason): string
    {
        if (empty($reason)) {
            return '-';
        }
        $reasons = config('attendance.absence_reasons', []);
        return $reasons[$reason] ?? $reason;
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
    }

    public function getMonthOptions(): array
    {
        $options = [];
        $current = now();

        for ($i = 12; $i >= -12; $i--) {
            $date = $current->copy()->subMonths($i);
            $key = $date->format('Y-m');
            $options[$key] = $date->format('Y年n月');
        }

        return $options;
    }

    public function updatedSelectedYear(): void
    {
        // Livewireで自動更新
    }

    public function updatedSelectedMonth(): void
    {
        // Livewireで自動更新
    }

    public function setMonth(string $yearMonth): void
    {
        [$year, $month] = explode('-', $yearMonth);
        $this->selectedYear = (int) $year;
        $this->selectedMonth = (int) $month;
    }

    public function getSelectedYearMonth(): string
    {
        return sprintf('%04d-%02d', $this->selectedYear, $this->selectedMonth);
    }

    public function formatWorkTime(?int $minutes): string
    {
        if ($minutes === null) {
            return '-';
        }
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%d:%02d', $hours, $mins);
    }

    public function formatTime(?string $time): string
    {
        if (empty($time)) {
            return '-';
        }
        return Carbon::parse($time)->format('H:i');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('CSVインポート')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('CSVファイル')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'application/vnd.ms-excel', 'text/plain'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $filePath = Storage::disk('local')->path($data['csv_file']);

                    try {
                        $importer = new AttendanceCsvImporter();
                        $result = $importer->import($filePath);

                        $message = "インポート完了: {$result['imported']}件";
                        if ($result['skipped'] > 0) {
                            $message .= " (スキップ: {$result['skipped']}件)";
                        }

                        Notification::make()
                            ->title($message)
                            ->success()
                            ->send();

                        if (!empty($result['errors'])) {
                            Notification::make()
                                ->title('エラー詳細')
                                ->body(implode("\n", array_slice($result['errors'], 0, 5)))
                                ->warning()
                                ->persistent()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('インポート失敗')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } finally {
                        Storage::disk('local')->delete($data['csv_file']);
                    }
                }),
            Actions\CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return '勤怠一覧';
    }
}
