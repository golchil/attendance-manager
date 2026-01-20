<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceCsvImporter
{
    protected array $headerColumns = [
        'カード番号',
        '従業員番号',
        '従業員氏名',
        '所属番号',
        '年/月/日',
        'シフト番号',
        '平日/休日区分',
        '不在理由',
        '出勤打刻',
        '出勤マーク',
        '外出打刻',
        '外出マーク',
        '戻打刻',
        '戻マーク',
        '退勤打刻',
        '退勤マーク',
        '例外１',
        '例外マーク',
        '例外２',
        '例外２マーク',
        'コメント',
    ];

    protected int $importedCount = 0;
    protected int $skippedCount = 0;
    protected array $errors = [];

    public function import(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
        $lines = explode("\n", $content);

        DB::beginTransaction();

        try {
            foreach ($lines as $lineNumber => $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $row = str_getcsv($line);

                if ($this->isHeaderRow($row)) {
                    continue;
                }

                $this->processRow($row, $lineNumber + 1);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CSV Import failed: ' . $e->getMessage());
            throw $e;
        }

        return [
            'imported' => $this->importedCount,
            'skipped' => $this->skippedCount,
            'errors' => $this->errors,
        ];
    }

    protected function isHeaderRow(array $row): bool
    {
        if (empty($row[0])) {
            return false;
        }
        return $row[0] === 'カード番号' || $row[0] === $this->headerColumns[0];
    }

    protected function processRow(array $row, int $lineNumber): void
    {
        if (count($row) < 21) {
            $this->errors[] = "行 {$lineNumber}: カラム数が不足しています";
            $this->skippedCount++;
            return;
        }

        $cardNumber = trim($row[0]);
        $employeeCode = trim($row[1]);
        $employeeName = trim($row[2]);
        $departmentCode = trim($row[3]);
        $dateStr = trim($row[4]);
        $shiftCode = trim($row[5]);
        $dayType = trim($row[6]);
        $absenceReason = trim($row[7]);
        $clockIn = trim($row[8]);
        $clockOut = trim($row[14]);
        $goOutAt = trim($row[10]);
        $returnAt = trim($row[12]);
        $comment = trim($row[20] ?? '');

        if (empty($dateStr)) {
            $this->errors[] = "行 {$lineNumber}: 日付が空です";
            $this->skippedCount++;
            return;
        }

        $user = $this->findOrCreateUser($employeeCode, $employeeName, $cardNumber, $departmentCode);

        if (!$user) {
            $this->errors[] = "行 {$lineNumber}: ユーザーの作成に失敗しました";
            $this->skippedCount++;
            return;
        }

        try {
            $date = $this->parseDate($dateStr);
        } catch (\Exception $e) {
            $this->errors[] = "行 {$lineNumber}: 日付の形式が不正です ({$dateStr})";
            $this->skippedCount++;
            return;
        }

        $attendanceData = [
            'user_id' => $user->id,
            'date' => $date,
            'shift_code' => $shiftCode ?: null,
            'day_type' => $this->normalizeDayType($dayType),
            'clock_in' => $this->parseTime($clockIn),
            'clock_out' => $this->parseTime($clockOut),
            'go_out_at' => $this->parseTime($goOutAt),
            'return_at' => $this->parseTime($returnAt),
            'status' => $this->mapStatus($absenceReason),
            'absence_reason' => $this->mapAbsenceReason($absenceReason),
            'note' => $comment ?: null,
        ];

        $attendanceData['break_minutes'] = $this->calculateBreakMinutes($attendanceData);
        $attendanceData['work_minutes'] = $this->calculateWorkMinutes($attendanceData);

        Attendance::updateOrCreate(
            ['user_id' => $user->id, 'date' => $date],
            $attendanceData
        );

        $this->importedCount++;
    }

    protected function findOrCreateUser(
        string $employeeCode,
        string $employeeName,
        string $cardNumber,
        string $departmentCode
    ): ?User {
        // 1. Search by employee_code
        if (!empty($employeeCode)) {
            $user = User::where('employee_code', $employeeCode)->first();
            if ($user) {
                return $user;
            }
        }

        // 2. Search by name (normalized_name, normalized_card_name, card_number)
        if (!empty($employeeName)) {
            $user = User::findByNameOrCard($employeeName);
            if ($user) {
                return $user;
            }
        }

        // 3. Search by card_number
        if (!empty($cardNumber)) {
            $user = User::where('card_number', $cardNumber)->first();
            if ($user) {
                return $user;
            }
        }

        // Create new user if not found
        $department = null;
        if ($departmentCode) {
            $department = Department::firstOrCreate(
                ['code' => $departmentCode],
                ['name' => '部署' . $departmentCode]
            );
        }

        return User::create([
            'employee_code' => $employeeCode,
            'name' => $employeeName,
            'email' => $employeeCode . '@example.com',
            'password' => bcrypt('password'),
            'card_number' => $cardNumber ?: null,
            'department_id' => $department?->id,
            'is_active' => true,
        ]);
    }

    protected function parseDate(string $dateStr): Carbon
    {
        $dateStr = str_replace(['/', '-'], '/', $dateStr);
        return Carbon::createFromFormat('Y/m/d', $dateStr)->startOfDay();
    }

    protected function parseTime(?string $timeStr): ?string
    {
        if (empty($timeStr)) {
            return null;
        }

        $timeStr = trim($timeStr);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $matches)) {
            return sprintf('%02d:%02d:00', $matches[1], $matches[2]);
        }

        if (preg_match('/^(\d{1,2})(\d{2})$/', $timeStr, $matches)) {
            return sprintf('%02d:%02d:00', $matches[1], $matches[2]);
        }

        return null;
    }

    /**
     * 日種別を正規化（config/attendance.phpの形式に合わせる）
     * 00 = 平日, 01 = 法定休日, 02 = 所定休日
     */
    protected function normalizeDayType(string $dayType): string
    {
        // すでに正規化されている場合
        if (in_array($dayType, ['00', '01', '02'])) {
            return $dayType;
        }

        return match ($dayType) {
            '平日', '0' => '00',
            '法定休日', '法定', '1' => '01',
            '所定休日', '所定', '土曜', '日曜', '祝日', '2' => '02',
            default => '00',
        };
    }

    /**
     * 不在理由からステータスをマッピング
     * CSVの値: 年休, 午前半休, 午後半休, 欠勤
     */
    protected function mapStatus(?string $absenceReason): ?string
    {
        if (empty($absenceReason)) {
            return 'present';
        }

        $absenceReason = trim($absenceReason);
        if (empty($absenceReason)) {
            return 'present';
        }

        return match ($absenceReason) {
            '年休' => 'paid_leave',
            '午前半休' => 'am_half_leave',
            '午後半休' => 'pm_half_leave',
            '欠勤' => 'absent',
            default => 'present',
        };
    }

    /**
     * 不在理由をシステム内部コードにマッピング
     * CSVの値: 年休, 午前半休, 午後半休, 欠勤
     */
    protected function mapAbsenceReason(?string $absenceReason): ?string
    {
        if (empty($absenceReason)) {
            return null;
        }

        $absenceReason = trim($absenceReason);
        if (empty($absenceReason)) {
            return null;
        }

        return match ($absenceReason) {
            '年休' => 'paid_leave',
            '午前半休' => 'am_half_leave',
            '午後半休' => 'pm_half_leave',
            '欠勤' => 'absence',
            default => $absenceReason, // 未知の値はそのまま保存
        };
    }

    protected function calculateBreakMinutes(array $data): ?int
    {
        if (empty($data['go_out_at']) || empty($data['return_at'])) {
            return null;
        }

        $goOut = Carbon::createFromFormat('H:i:s', $data['go_out_at']);
        $return = Carbon::createFromFormat('H:i:s', $data['return_at']);

        return $return->diffInMinutes($goOut);
    }

    protected function calculateWorkMinutes(array $data): ?int
    {
        if (empty($data['clock_in']) || empty($data['clock_out'])) {
            return null;
        }

        $clockIn = Carbon::createFromFormat('H:i:s', $data['clock_in']);
        $clockOut = Carbon::createFromFormat('H:i:s', $data['clock_out']);

        $totalMinutes = $clockOut->diffInMinutes($clockIn);
        $breakMinutes = $data['break_minutes'] ?? 0;

        return max(0, $totalMinutes - $breakMinutes);
    }
}
