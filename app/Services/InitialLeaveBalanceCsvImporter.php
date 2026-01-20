<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InitialLeaveBalanceCsvImporter
{
    protected int $importedCount = 0;
    protected int $skippedCount = 0;
    protected array $errors = [];

    /**
     * Import initial leave balance from CSV
     *
     * CSV Format: 社員番号,氏名,賃金計算期間開始日,賃金計算期間終了日,有休残
     * Example: 000004,上武　政一,12月21日,01月20日,3.0
     *
     * Only updates employees whose leave_grant_month matches the CSV's period end month
     */
    public function import(string $filePath): array
    {
        $this->importedCount = 0;
        $this->skippedCount = 0;
        $this->errors = [];

        $content = file_get_contents($filePath);

        // Detect encoding and convert to UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $lines = explode("\n", $content);

        DB::beginTransaction();

        try {
            foreach ($lines as $lineNumber => $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $row = str_getcsv($line, ',', '"', '');

                // Skip header row
                if ($this->isHeaderRow($row, $lineNumber)) {
                    continue;
                }

                $this->processRow($row, $lineNumber + 1);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Initial Leave Balance CSV Import failed: ' . $e->getMessage());
            throw $e;
        }

        return [
            'imported' => $this->importedCount,
            'skipped' => $this->skippedCount,
            'errors' => $this->errors,
        ];
    }

    protected function isHeaderRow(array $row, int $lineNumber): bool
    {
        if ($lineNumber === 0) {
            $firstColumn = mb_convert_kana(trim($row[0] ?? ''), 'KVas');
            $headerKeywords = ['社員番号', '従業員番号', '氏名', '名前', 'Name', 'name', 'employee_code'];
            if (in_array($firstColumn, $headerKeywords)) {
                return true;
            }
        }
        return false;
    }

    protected function processRow(array $row, int $lineNumber): void
    {
        $columnCount = count($row);

        if ($columnCount < 5) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => 'カラム数が不足しています（5カラム必要: 社員番号,氏名,開始日,終了日,有休残）',
            ];
            $this->skippedCount++;
            return;
        }

        // Parse columns: 社員番号,氏名,賃金計算期間開始日,賃金計算期間終了日,有休残
        $employeeCode = trim($row[0] ?? '');
        $name = trim($row[1] ?? '');
        $periodStart = trim($row[2] ?? ''); // Not used but validated
        $periodEnd = trim($row[3] ?? '');
        $balance = trim($row[4] ?? '');

        // Parse month from periodEnd (e.g., "01月20日" → 1)
        $csvMonth = $this->parseMonth($periodEnd);
        if ($csvMonth === null) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "賃金計算期間終了日の月が解析できません: {$periodEnd}",
            ];
            $this->skippedCount++;
            return;
        }

        // Find user by employee_code or name
        $user = $this->findUser($employeeCode, $name);
        if (!$user) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "従業員が見つかりません: {$employeeCode} / {$name}",
            ];
            $this->skippedCount++;
            return;
        }

        // Check if user's leave_grant_month matches CSV's month
        if ($user->leave_grant_month === null) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "付与基準月が未設定: {$user->name}",
            ];
            $this->skippedCount++;
            return;
        }

        if ($user->leave_grant_month !== $csvMonth) {
            // Month doesn't match - skip silently (not an error)
            $this->skippedCount++;
            return;
        }

        // Validate balance
        if (!is_numeric($balance)) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "残日数が数値ではありません: {$balance}",
            ];
            $this->skippedCount++;
            return;
        }

        $balanceValue = (float) $balance;
        if ($balanceValue < 0 || $balanceValue > 40) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "残日数が範囲外です（0〜40）: {$balance}",
            ];
            $this->skippedCount++;
            return;
        }

        // Update initial_leave_balance and mark as imported
        $user->update([
            'initial_leave_balance' => $balanceValue,
            'initial_leave_imported' => true,
        ]);

        $this->importedCount++;
    }

    /**
     * Parse month from date string like "01月20日" or "1月20日"
     */
    protected function parseMonth(string $dateStr): ?int
    {
        // Normalize to half-width
        $dateStr = mb_convert_kana($dateStr, 'n');

        // Try to match "MM月" pattern
        if (preg_match('/(\d{1,2})月/', $dateStr, $matches)) {
            $month = (int) $matches[1];
            if ($month >= 1 && $month <= 12) {
                return $month;
            }
        }

        return null;
    }

    protected function findUser(string $employeeCode, string $name): ?User
    {
        // First try to find by employee_code
        if (!empty($employeeCode)) {
            $user = User::where('employee_code', $employeeCode)->first();
            if ($user) {
                return $user;
            }
        }

        // Try to find by name (normalized_name → normalized_card_name → card_number)
        if (!empty($name)) {
            $user = User::findByNameOrCard($name);
            if ($user) {
                return $user;
            }
        }

        return null;
    }
}
