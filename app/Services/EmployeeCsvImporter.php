<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeCsvImporter
{
    protected int $createdCount = 0;
    protected int $updatedCount = 0;
    protected int $skippedCount = 0;
    protected array $errors = [];

    /**
     * Import employees from CSV
     *
     * CSV Format: 社員番号,氏名[,入社日,有給付与基準日,有給付与月]
     * Example: 000001,小久保正一
     * Example: 000004,上武　政一,1987/2/1,1988/2/1,2
     */
    public function import(string $filePath): array
    {
        $this->createdCount = 0;
        $this->updatedCount = 0;
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
            Log::error('Employee CSV Import failed: ' . $e->getMessage());
            throw $e;
        }

        return [
            'created' => $this->createdCount,
            'updated' => $this->updatedCount,
            'skipped' => $this->skippedCount,
            'errors' => $this->errors,
        ];
    }

    protected function isHeaderRow(array $row, int $lineNumber): bool
    {
        if ($lineNumber === 0) {
            $firstColumn = mb_convert_kana(trim($row[0] ?? ''), 'KVas');
            if (in_array($firstColumn, ['社員番号', '従業員番号', 'employee_code', 'EmployeeCode'])) {
                return true;
            }
        }
        return false;
    }

    protected function processRow(array $row, int $lineNumber): void
    {
        if (count($row) < 2) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => 'カラム数が不足しています（最低2カラム必要: 社員番号,氏名）',
            ];
            $this->skippedCount++;
            return;
        }

        // Parse columns
        $employeeCode = trim($row[0] ?? '');
        $name = trim($row[1] ?? '');
        $joinedAtStr = trim($row[2] ?? '');
        $leaveGrantDateStr = trim($row[3] ?? '');
        $leaveGrantMonth = trim($row[4] ?? '');

        // Validate employee code
        if (empty($employeeCode)) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => '社員番号が空です',
            ];
            $this->skippedCount++;
            return;
        }

        // Validate name
        if (empty($name)) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => '氏名が空です',
            ];
            $this->skippedCount++;
            return;
        }

        $normalizedName = User::normalizeName($name);

        // Parse optional fields
        $joinedAt = null;
        if (!empty($joinedAtStr)) {
            try {
                $joinedAt = $this->parseDate($joinedAtStr);
            } catch (\Exception $e) {
                $this->errors[] = [
                    'line' => $lineNumber,
                    'data' => implode(',', $row),
                    'message' => "入社日の形式が不正です: {$joinedAtStr}",
                ];
                $this->skippedCount++;
                return;
            }
        }

        $leaveGrantDate = null;
        if (!empty($leaveGrantDateStr)) {
            try {
                $leaveGrantDate = $this->parseDate($leaveGrantDateStr);
            } catch (\Exception $e) {
                $this->errors[] = [
                    'line' => $lineNumber,
                    'data' => implode(',', $row),
                    'message' => "有給付与基準日の形式が不正です: {$leaveGrantDateStr}",
                ];
                $this->skippedCount++;
                return;
            }
        }

        $leaveGrantMonthValue = null;
        if (!empty($leaveGrantMonth)) {
            if (!is_numeric($leaveGrantMonth)) {
                $this->errors[] = [
                    'line' => $lineNumber,
                    'data' => implode(',', $row),
                    'message' => "有給付与月が数値ではありません: {$leaveGrantMonth}",
                ];
                $this->skippedCount++;
                return;
            }
            $leaveGrantMonthValue = (int) $leaveGrantMonth;
            if ($leaveGrantMonthValue < 1 || $leaveGrantMonthValue > 12) {
                $this->errors[] = [
                    'line' => $lineNumber,
                    'data' => implode(',', $row),
                    'message' => "有給付与月が範囲外です（1〜12）: {$leaveGrantMonth}",
                ];
                $this->skippedCount++;
                return;
            }
        }

        // Find existing user by normalized_name first
        $user = User::where('normalized_name', $normalizedName)->first();
        $isNew = false;

        if (!$user) {
            // Also check by employee_code
            $user = User::where('employee_code', $employeeCode)->first();
        }

        if (!$user) {
            // Create new user
            $isNew = true;
            $user = new User();
            $user->email = $employeeCode . '@placeholder.local';
            $user->password = bcrypt('temporary_' . $employeeCode);
            $user->is_active = true;
        }

        // Update fields
        $user->employee_code = $employeeCode;
        $user->name = $name; // This will auto-set normalized_name via setNameAttribute

        // Only update optional fields if provided in CSV
        if ($joinedAt !== null) {
            $user->joined_at = $joinedAt;
        }
        if ($leaveGrantDate !== null) {
            $user->leave_grant_date = $leaveGrantDate;
        }
        if ($leaveGrantMonthValue !== null) {
            $user->leave_grant_month = $leaveGrantMonthValue;
        }

        $user->save();

        if ($isNew) {
            $this->createdCount++;
        } else {
            $this->updatedCount++;
        }
    }

    protected function parseDate(string $dateStr): Carbon
    {
        $dateStr = str_replace(['-', '.'], '/', $dateStr);

        $parts = explode('/', $dateStr);
        if (count($parts) === 3) {
            $year = (int) $parts[0];
            $month = (int) $parts[1];
            $day = (int) $parts[2];
            return Carbon::create($year, $month, $day)->startOfDay();
        }

        return Carbon::parse($dateStr)->startOfDay();
    }
}
