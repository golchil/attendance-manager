<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\EmployeeCsvImporter;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public array $importResult = [];
    public bool $showImportResult = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('従業員インポート')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('CSVファイル')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->helperText('フォーマット: 社員番号,氏名（入社日等は省略可）'),
                ])
                ->action(function (array $data) {
                    $this->importCsv($data['csv_file']);
                })
                ->modalHeading('従業員のインポート')
                ->modalDescription('給与奉行から出力したCSVファイルをアップロードして、社員番号を更新します。氏名で既存従業員を検索し、見つかれば社員番号を更新、見つからなければ新規作成します。')
                ->modalSubmitActionLabel('インポート実行'),
            Actions\CreateAction::make(),
        ];
    }

    public function importCsv(string $relativePath): void
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

            $importer = new EmployeeCsvImporter();
            $result = $importer->import($filePath);

            $this->importResult = $result;
            $this->showImportResult = true;

            $total = $result['created'] + $result['updated'];
            if ($total > 0 && empty($result['errors'])) {
                Notification::make()
                    ->title('インポート完了')
                    ->body("新規: {$result['created']}件、更新: {$result['updated']}件")
                    ->success()
                    ->send();
            } elseif ($total > 0 && !empty($result['errors'])) {
                Notification::make()
                    ->title('インポート完了（一部エラー）')
                    ->body("新規: {$result['created']}件、更新: {$result['updated']}件、スキップ: {$result['skipped']}件")
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
}
