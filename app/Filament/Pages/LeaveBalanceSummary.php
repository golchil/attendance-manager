<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\InitialLeaveBalanceCsvImporter;
use App\Services\PaidLeaveService;
use App\Services\PaidLeaveUsageCsvImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class LeaveBalanceSummary extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = '勤怠管理';

    protected static ?string $title = '有給残日数一覧';

    protected static ?string $navigationLabel = '有給残日数一覧';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.leave-balance-summary';

    public array $importResult = [];
    public bool $showImportResult = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('有給消化履歴インポート')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('CSVファイル')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('フォーマット: 氏名,日付,有給,午前/午後（Shift-JIS対応）'),
                ])
                ->action(function (array $data) {
                    $this->importCsvFromPath($data['csv_file']);
                })
                ->modalHeading('有給消化履歴のインポート')
                ->modalDescription('CSVファイルをアップロードして、有給消化履歴をインポートします。')
                ->modalSubmitActionLabel('インポート実行'),
            Action::make('importInitialBalance')
                ->label('初期残日数インポート')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('CSVファイル')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('フォーマット: 社員番号,氏名,賃金計算期間開始日,賃金計算期間終了日,有休残'),
                ])
                ->action(function (array $data) {
                    $this->importInitialBalanceFromPath($data['csv_file']);
                })
                ->modalHeading('初期残日数のインポート')
                ->modalDescription('CSVファイルから従業員の初期残日数を設定します。賃金計算期間終了日の月と付与基準月が一致する従業員のみ更新されます。')
                ->modalSubmitActionLabel('インポート実行'),
        ];
    }

    public function importCsvFromPath(string $relativePath): void
    {
        $filePath = Storage::disk('local')->path($relativePath);

        try {
            if (!file_exists($filePath)) {
                Notification::make()
                    ->title('エラー')
                    ->body('ファイルが見つかりません')
                    ->danger()
                    ->send();
                return;
            }

            $importer = new PaidLeaveUsageCsvImporter();
            $result = $importer->import($filePath);

            $this->importResult = $result;
            $this->showImportResult = true;

            if ($result['imported'] > 0 && empty($result['errors'])) {
                Notification::make()
                    ->title('インポート完了')
                    ->body("{$result['imported']}件のデータをインポートしました。")
                    ->success()
                    ->send();
            } elseif ($result['imported'] > 0 && !empty($result['errors'])) {
                Notification::make()
                    ->title('インポート完了（一部エラー）')
                    ->body("{$result['imported']}件インポート、{$result['skipped']}件スキップ")
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title('インポート失敗')
                    ->body('インポートできたデータがありません。エラー内容を確認してください。')
                    ->danger()
                    ->send();
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('インポートエラー')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            // Clean up uploaded file
            Storage::disk('local')->delete($relativePath);
        }
    }

    public function importInitialBalanceFromPath(string $relativePath): void
    {
        $filePath = Storage::disk('local')->path($relativePath);

        try {
            if (!file_exists($filePath)) {
                Notification::make()
                    ->title('エラー')
                    ->body('ファイルが見つかりません')
                    ->danger()
                    ->send();
                return;
            }

            $importer = new InitialLeaveBalanceCsvImporter();
            $result = $importer->import($filePath);

            $this->importResult = $result;
            $this->showImportResult = true;

            if ($result['imported'] > 0 && empty($result['errors'])) {
                Notification::make()
                    ->title('インポート完了')
                    ->body("{$result['imported']}件の初期残日数を設定しました。")
                    ->success()
                    ->send();
            } elseif ($result['imported'] > 0 && !empty($result['errors'])) {
                Notification::make()
                    ->title('インポート完了（一部エラー）')
                    ->body("{$result['imported']}件設定、{$result['skipped']}件スキップ")
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title('インポート失敗')
                    ->body('インポートできたデータがありません。エラー内容を確認してください。')
                    ->danger()
                    ->send();
            }

        } catch (\Exception $e) {
            Notification::make()
                ->title('インポートエラー')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            // Clean up uploaded file
            Storage::disk('local')->delete($relativePath);
        }
    }

    public function closeImportResult(): void
    {
        $this->showImportResult = false;
        $this->importResult = [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->where('is_active', true)
                    ->with(['department', 'paidLeaveGrants'])
            )
            ->columns([
                Tables\Columns\TextColumn::make('employee_code')
                    ->label('社員番号')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('氏名')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('joined_at')
                    ->label('入社日')
                    ->date('Y/m/d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('leave_grant_date')
                    ->label('基準日')
                    ->date('Y/m/d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('leave_grant_month')
                    ->label('付与月')
                    ->getStateUsing(fn ($record) => $record->leave_grant_month ? $record->leave_grant_month . '月' : '-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('initial_leave_imported')
                    ->label('初期データ')
                    ->getStateUsing(fn ($record) => $record->initial_leave_imported ? '✓' : '-')
                    ->alignCenter()
                    ->color(fn ($record) => $record->initial_leave_imported ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('total_used')
                    ->label('消化')
                    ->getStateUsing(function ($record) {
                        $service = app(PaidLeaveService::class);
                        $balance = $service->calculateBalance($record);
                        return $balance['total_used'];
                    })
                    ->suffix('日'),
                Tables\Columns\TextColumn::make('total_remaining')
                    ->label('残日数')
                    ->getStateUsing(function ($record) {
                        $service = app(PaidLeaveService::class);
                        $balance = $service->calculateBalance($record);
                        return $balance['total_remaining'];
                    })
                    ->suffix('日')
                    ->color(fn ($state) => $state <= 5 ? 'warning' : 'success')
                    ->weight('bold'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('leave_grant_month')
                    ->label('付与月')
                    ->options([
                        1 => '1月',
                        2 => '2月',
                        3 => '3月',
                        4 => '4月',
                        5 => '5月',
                        6 => '6月',
                        7 => '7月',
                        8 => '8月',
                        9 => '9月',
                        10 => '10月',
                        11 => '11月',
                        12 => '12月',
                    ]),
                Tables\Filters\SelectFilter::make('department')
                    ->relationship('department', 'name')
                    ->label('部署'),
            ])
            ->defaultSort('employee_code', 'asc');
    }
}
