<?php

namespace App\Services;

use App\Models\PaidLeaveUsage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaidLeaveUsageCsvImporter
{
    protected int $importedCount = 0;
    protected int $skippedCount = 0;
    protected array $errors = [];

    /**
     * Import paid leave usage from CSV
     *
     * CSV Format: 氏名,日付,有給,午前/午後
     * Example: ﾌﾞﾄﾞｩﾙ ｶﾘﾑ,2019/4/30,0.5,午前
     * Example: 上武　政一,2019/4/30,1,
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
            Log::error('Paid Leave Usage CSV Import failed: ' . $e->getMessage());
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
        // First row is header if it contains column names
        if ($lineNumber === 0) {
            $firstColumn = mb_convert_kana(trim($row[0] ?? ''), 'KVas');
            if (in_array($firstColumn, ['氏名', '名前', 'Name', 'name'])) {
                return true;
            }
        }
        return false;
    }

    protected function processRow(array $row, int $lineNumber): void
    {
        if (count($row) < 3) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => 'カラム数が不足しています（最低3カラム必要）',
            ];
            $this->skippedCount++;
            return;
        }

        // Parse columns
        $name = $this->normalizeName(trim($row[0] ?? ''));
        $dateStr = trim($row[1] ?? '');
        $leaveDays = trim($row[2] ?? '');
        $amPm = trim($row[3] ?? '');

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

        // Find user by name
        $user = $this->findUserByName($name);
        if (!$user) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "従業員が見つかりません: {$name}",
            ];
            $this->skippedCount++;
            return;
        }

        // Validate date
        if (empty($dateStr)) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => '日付が空です',
            ];
            $this->skippedCount++;
            return;
        }

        try {
            $date = $this->parseDate($dateStr);
        } catch (\Exception $e) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "日付の形式が不正です: {$dateStr}",
            ];
            $this->skippedCount++;
            return;
        }

        // Determine leave type and days
        $leaveType = $this->determineLeaveType($leaveDays, $amPm);
        $days = $this->determineDays($leaveDays);

        if (!$leaveType || $days === null) {
            $this->errors[] = [
                'line' => $lineNumber,
                'data' => implode(',', $row),
                'message' => "有給の値が不正です: 有給={$leaveDays}, 午前/午後={$amPm}",
            ];
            $this->skippedCount++;
            return;
        }

        // Check for duplicate (same user, date, leave_type)
        $exists = PaidLeaveUsage::where('user_id', $user->id)
            ->where('date', $date)
            ->where('leave_type', $leaveType)
            ->exists();

        if ($exists) {
            // Skip duplicate
            $this->skippedCount++;
            return;
        }

        // Create paid leave usage record
        PaidLeaveUsage::create([
            'user_id' => $user->id,
            'date' => $date,
            'leave_type' => $leaveType,
            'days' => $days,
        ]);

        $this->importedCount++;
    }

    /**
     * Normalize name: convert half-width katakana to full-width
     */
    protected function normalizeName(string $name): string
    {
        // Convert half-width katakana to full-width
        return mb_convert_kana($name, 'KVas');
    }

    /**
     * Find user by name (with normalized matching)
     * Priority: normalized_name → normalized_card_name → card_number
     */
    protected function findUserByName(string $name): ?User
    {
        return User::findByNameOrCard($name);
    }

    protected function parseDate(string $dateStr): Carbon
    {
        $dateStr = str_replace(['-', '.'], '/', $dateStr);

        // Handle Y/m/d or Y/n/j format
        $parts = explode('/', $dateStr);
        if (count($parts) === 3) {
            $year = (int) $parts[0];
            $month = (int) $parts[1];
            $day = (int) $parts[2];
            return Carbon::create($year, $month, $day)->startOfDay();
        }

        return Carbon::parse($dateStr)->startOfDay();
    }

    /**
     * Determine leave type based on leave days and AM/PM
     */
    protected function determineLeaveType(string $leaveDays, string $amPm): ?string
    {
        $days = (float) $leaveDays;

        if ($days === 1.0 || $days === 1) {
            return 'paid_leave';
        }

        if ($days === 0.5) {
            $amPm = mb_convert_kana($amPm, 'KVas'); // Normalize
            if (in_array($amPm, ['午前', 'AM', 'am', '前'])) {
                return 'am_half_leave';
            }
            if (in_array($amPm, ['午後', 'PM', 'pm', '後'])) {
                return 'pm_half_leave';
            }
            // Default to AM if not specified
            return 'am_half_leave';
        }

        return null;
    }

    /**
     * Determine days value
     */
    protected function determineDays(string $leaveDays): ?float
    {
        $days = (float) $leaveDays;

        if ($days === 1.0 || $days === 1) {
            return 1.0;
        }

        if ($days === 0.5) {
            return 0.5;
        }

        return null;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
