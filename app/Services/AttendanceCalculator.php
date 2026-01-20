<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceCalculator
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('attendance');
    }

    /**
     * 1日分の勤怠から各種時間を計算（36協定対応）
     */
    public function calculateDaily(Attendance $attendance): array
    {
        $result = [
            'work_minutes' => 0,                    // 実働時間（所定＋残業）
            'regular_minutes' => 0,                  // 所定内労働時間
            'overtime_minutes' => 0,                 // 普通残業時間（平日17:00～22:00）
            'night_minutes' => 0,                    // 深夜残業時間（22:00～翌05:00）
            'prescribed_holiday_minutes' => 0,       // 所定休日労働時間
            'holiday_minutes' => 0,                  // 法定休日労働時間
            'is_legal_holiday' => false,             // 法定休日かどうか
            'is_prescribed_holiday' => false,        // 所定休日かどうか
        ];

        // 打刻がない場合は計算しない
        if (empty($attendance->clock_in) || empty($attendance->clock_out)) {
            return $result;
        }

        // 基準日（勤務日）
        $baseDate = Carbon::parse($attendance->date)->startOfDay();

        // 時刻をパース
        $clockIn = $this->parseTimeWithDate($attendance->clock_in, $baseDate);
        $clockOut = $this->parseTimeWithDate($attendance->clock_out, $baseDate);

        // 退勤が出勤より前の場合は翌日とみなす（深夜勤務対応）
        if ($clockOut->lt($clockIn)) {
            $clockOut->addDay();
        }

        // 基準時刻を設定
        $regularStart = $this->parseTime($this->config['regular_hours']['start'], $baseDate); // 08:00
        $regularEnd = $this->parseTime($this->config['regular_hours']['end'], $baseDate);     // 16:55
        $overtimeStart = $this->parseTime($this->config['overtime']['start'], $baseDate);     // 17:00
        $nightStart = $this->parseTime($this->config['night']['start'], $baseDate);           // 22:00
        $nightEnd = $this->parseTime($this->config['night']['end'], $baseDate->copy()->addDay()); // 翌05:00

        // 日種別の判定
        $dayType = $attendance->day_type ?? '00';
        $dayTypeConfig = $this->config['day_types'][$dayType] ?? $this->config['day_types']['00'];
        $isHoliday = $dayTypeConfig['is_holiday'] ?? false;
        $isLegalHoliday = $dayTypeConfig['is_legal_holiday'] ?? false;

        $result['is_legal_holiday'] = $isLegalHoliday;
        $result['is_prescribed_holiday'] = $isHoliday && !$isLegalHoliday;

        // === 出勤・退勤時刻の丸め処理 ===

        // 1. 出勤時刻: 定時前は定時開始に丸める
        $effectiveClockIn = $clockIn->lt($regularStart) ? $regularStart->copy() : $clockIn->copy();

        // 2. 退勤時刻: 16:55～17:00の間は16:55として扱う
        $effectiveClockOut = $clockOut->copy();
        if ($clockOut->gte($regularEnd) && $clockOut->lt($overtimeStart)) {
            $effectiveClockOut = $regularEnd->copy();
        }

        // === 法定休日の場合 ===
        if ($isLegalHoliday) {
            return $this->calculateHolidayWork($effectiveClockIn, $clockOut, $baseDate, $result);
        }

        // === 所定休日の場合（全て残業扱い） ===
        if ($isHoliday && !$isLegalHoliday) {
            return $this->calculatePrescribedHolidayWork($effectiveClockIn, $clockOut, $baseDate, $result);
        }

        // === 平日の計算 ===

        // 所定労働時間の計算（8:00～16:55の範囲）
        if ($effectiveClockOut->gt($regularStart)) {
            $regularWorkStart = $effectiveClockIn->gt($regularStart) ? $effectiveClockIn : $regularStart;
            $regularWorkEnd = $effectiveClockOut->lt($regularEnd) ? $effectiveClockOut : $regularEnd;

            if ($regularWorkEnd->gt($regularWorkStart)) {
                $regularTotalMinutes = $regularWorkStart->diffInMinutes($regularWorkEnd);
                $regularBreakMinutes = $this->calculateBreakMinutes($regularWorkStart, $regularWorkEnd);
                $result['regular_minutes'] = max(0, $regularTotalMinutes - $regularBreakMinutes);
            }
        }

        // 残業時間の計算（17:00以降）
        if ($clockOut->gt($overtimeStart)) {
            if ($clockOut->lte($nightStart)) {
                // 17:00～22:00の間に退勤
                $result['overtime_minutes'] = $overtimeStart->diffInMinutes($clockOut);
            } else {
                // 22:00以降も勤務
                $result['overtime_minutes'] = $overtimeStart->diffInMinutes($nightStart); // 17:00～22:00 = 300分
                $result['night_minutes'] = $this->calculateNightMinutes($clockIn, $clockOut, $baseDate);
            }
        }

        // 実働時間 = 所定 + 残業 + 深夜
        $result['work_minutes'] = $result['regular_minutes'] + $result['overtime_minutes'] + $result['night_minutes'];

        return $result;
    }

    /**
     * 法定休日の労働時間を計算
     * ※休日出勤でも所定労働日と同じ休憩時間を引いて計算
     */
    protected function calculateHolidayWork(Carbon $clockIn, Carbon $clockOut, Carbon $baseDate, array $result): array
    {
        $nightStart = $this->parseTime($this->config['night']['start'], $baseDate);

        $totalMinutes = $clockIn->diffInMinutes($clockOut);
        // 所定労働日と同じ休憩時間を適用
        $breakMinutes = $this->calculateBreakMinutes($clockIn, $clockOut);
        $workMinutes = max(0, $totalMinutes - $breakMinutes);

        // 深夜時間帯を分離
        $nightMinutes = 0;
        if ($clockOut->gt($nightStart)) {
            $nightMinutes = $this->calculateNightMinutes($clockIn, $clockOut, $baseDate);
        }

        $result['work_minutes'] = $workMinutes;
        $result['holiday_minutes'] = max(0, $workMinutes - $nightMinutes);
        $result['night_minutes'] = $nightMinutes;
        $result['is_legal_holiday'] = true;

        return $result;
    }

    /**
     * 所定休日の労働時間を計算
     * ※休日出勤でも所定労働日と同じ休憩時間を引いて計算
     * ※所定休日労働は36協定対象
     */
    protected function calculatePrescribedHolidayWork(Carbon $clockIn, Carbon $clockOut, Carbon $baseDate, array $result): array
    {
        $nightStart = $this->parseTime($this->config['night']['start'], $baseDate);

        $totalMinutes = $clockIn->diffInMinutes($clockOut);
        // 所定労働日と同じ休憩時間を適用
        $breakMinutes = $this->calculateBreakMinutes($clockIn, $clockOut);
        $workMinutes = max(0, $totalMinutes - $breakMinutes);

        // 深夜時間帯を分離
        $nightMinutes = 0;
        if ($clockOut->gt($nightStart)) {
            $nightMinutes = $this->calculateNightMinutes($clockIn, $clockOut, $baseDate);
        }

        $result['work_minutes'] = $workMinutes;
        // 所定休日労働時間（深夜を除く）
        $result['prescribed_holiday_minutes'] = max(0, $workMinutes - $nightMinutes);
        // 深夜時間（所定休日でも36協定対象）
        $result['night_minutes'] = $nightMinutes;
        $result['is_prescribed_holiday'] = true;

        return $result;
    }

    /**
     * 深夜時間帯の勤務時間を計算（22:00～翌05:00）
     */
    protected function calculateNightMinutes(Carbon $clockIn, Carbon $clockOut, Carbon $baseDate): int
    {
        $nightStart = $this->parseTime($this->config['night']['start'], $baseDate);
        $nightEnd = $this->parseTime($this->config['night']['end'], $baseDate->copy()->addDay());

        $nightMinutes = 0;

        // 22:00以降の勤務時間
        if ($clockOut->gt($nightStart)) {
            $effectiveStart = $clockIn->gt($nightStart) ? $clockIn : $nightStart;
            $effectiveEnd = $clockOut->lt($nightEnd) ? $clockOut : $nightEnd;

            if ($effectiveEnd->gt($effectiveStart)) {
                $nightMinutes = $effectiveStart->diffInMinutes($effectiveEnd);
            }
        }

        // 早朝（0:00〜5:00）の深夜勤務をチェック
        $earlyMorningEnd = $this->parseTime($this->config['night']['end'], $baseDate);
        if ($clockIn->lt($earlyMorningEnd)) {
            $effectiveEnd = $clockOut->lt($earlyMorningEnd) ? $clockOut : $earlyMorningEnd;
            $nightMinutes += $clockIn->diffInMinutes($effectiveEnd);
        }

        return $nightMinutes;
    }

    /**
     * 休憩時間を計算（勤務時間帯と休憩時間帯の重複）
     */
    protected function calculateBreakMinutes(Carbon $clockIn, Carbon $clockOut): int
    {
        $totalBreak = 0;
        $baseDate = $clockIn->copy()->startOfDay();

        foreach ($this->config['breaks'] as $break) {
            $breakStart = $this->parseTime($break['start'], $baseDate);
            $breakEnd = $this->parseTime($break['end'], $baseDate);

            // 勤務時間と休憩時間の重複を計算
            if ($clockIn->lt($breakEnd) && $clockOut->gt($breakStart)) {
                $effectiveStart = $clockIn->gt($breakStart) ? $clockIn : $breakStart;
                $effectiveEnd = $clockOut->lt($breakEnd) ? $clockOut : $breakEnd;
                $totalBreak += $effectiveStart->diffInMinutes($effectiveEnd);
            }
        }

        return $totalBreak;
    }

    /**
     * 時刻文字列をCarbonに変換
     */
    protected function parseTime(string $time, Carbon $baseDate): Carbon
    {
        return Carbon::parse($baseDate->format('Y-m-d') . ' ' . $time);
    }

    /**
     * 時刻文字列（H:i:s or H:i）を指定日付のCarbonに変換
     */
    protected function parseTimeWithDate(string $time, Carbon $baseDate): Carbon
    {
        $timePart = substr($time, 0, 5);
        return Carbon::parse($baseDate->format('Y-m-d') . ' ' . $timePart . ':00');
    }

    /**
     * 月度の残業集計を計算（36協定対応）
     *
     * 【36協定対象時間】
     * - 普通残業（平日17:00〜22:00）
     * - 深夜残業（平日・所定休日の22:00〜翌05:00）
     * - 所定休日労働
     *
     * 【36協定対象外】
     * - 法定休日労働（別枠でカウント）
     */
    public function calculateMonthlyTotal(Collection $attendances): array
    {
        $result = [
            'work_minutes' => 0,                    // 総実働時間
            'regular_minutes' => 0,                  // 所定労働時間合計
            'overtime_minutes' => 0,                 // 普通残業合計（平日17:00〜22:00）
            'night_minutes' => 0,                    // 深夜残業合計（22:00〜翌05:00）
            'prescribed_holiday_minutes' => 0,       // 所定休日労働合計
            'holiday_minutes' => 0,                  // 法定休日労働合計
            'article36_minutes' => 0,                // 36協定対象時間合計
            'overtime_over60_minutes' => 0,          // 60時間超過分
            'night_over60_minutes' => 0,             // 60時間超過深夜分
            'work_days' => 0,
        ];

        $dailyResults = [];

        // まず各日の計算を行う
        foreach ($attendances as $attendance) {
            $daily = $this->calculateDaily($attendance);
            $dailyResults[] = [
                'attendance' => $attendance,
                'calculation' => $daily,
            ];

            $result['work_minutes'] += $daily['work_minutes'];
            $result['regular_minutes'] += $daily['regular_minutes'];
            $result['holiday_minutes'] += $daily['holiday_minutes'];
            $result['prescribed_holiday_minutes'] += $daily['prescribed_holiday_minutes'];

            if ($daily['work_minutes'] > 0) {
                $result['work_days']++;
            }
        }

        // 60時間の閾値（分単位）
        $overtimeLimit = $this->config['overtime_limit'];
        $accumulated36Hours = 0;

        // 日付順に処理して36協定対象時間と60時間超過を計算
        foreach ($dailyResults as $item) {
            $daily = $item['calculation'];

            // 法定休日は36協定対象外（別枠）
            if ($daily['is_legal_holiday']) {
                continue;
            }

            // 36協定対象時間を計算
            // 平日: 普通残業 + 深夜残業
            // 所定休日: 所定休日労働 + 深夜残業
            $dailyArticle36 = $daily['overtime_minutes']
                            + $daily['night_minutes']
                            + $daily['prescribed_holiday_minutes'];

            $dailyNight = $daily['night_minutes'];

            // 60時間超過判定
            if ($accumulated36Hours + $dailyArticle36 <= $overtimeLimit) {
                // まだ60時間以内
                $result['overtime_minutes'] += $daily['overtime_minutes'];
                $result['night_minutes'] += $dailyNight;
            } else {
                // 60時間を超える
                $underLimit = max(0, $overtimeLimit - $accumulated36Hours);
                $overLimit = $dailyArticle36 - $underLimit;

                // 60時間以内の部分
                if ($underLimit > 0 && $dailyArticle36 > 0) {
                    $ratio = $underLimit / $dailyArticle36;
                    $result['overtime_minutes'] += (int)($daily['overtime_minutes'] * $ratio);
                    $result['night_minutes'] += (int)($dailyNight * $ratio);
                }

                // 60時間超過部分
                $result['overtime_over60_minutes'] += $overLimit;
                if ($dailyArticle36 > 0) {
                    $overRatio = $overLimit / $dailyArticle36;
                    $result['night_over60_minutes'] += (int)($dailyNight * $overRatio);
                }
            }

            $accumulated36Hours += $dailyArticle36;
        }

        // 36協定対象時間合計 = 普通残業 + 深夜 + 所定休日労働 + 60時間超過分
        $result['article36_minutes'] = $result['overtime_minutes']
                                     + $result['night_minutes']
                                     + $result['prescribed_holiday_minutes']
                                     + $result['overtime_over60_minutes']
                                     + $result['night_over60_minutes'];

        return $result;
    }

    /**
     * 分を時間:分形式に変換
     */
    public function formatMinutes(int $minutes): string
    {
        if ($minutes === 0) {
            return '-';
        }
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%d:%02d', $hours, $mins);
    }

    /**
     * 勤怠データの異常を検知
     *
     * @return array ['type' => 'warning_type', 'icon' => '絵文字', 'message' => '詳細メッセージ']
     */
    public function detectAnomalies(Attendance $attendance, array $dailyCalculation): array
    {
        $warnings = [];

        $dayType = $attendance->day_type ?? '00';
        $dayTypeConfig = $this->config['day_types'][$dayType] ?? $this->config['day_types']['00'];
        $isHoliday = $dayTypeConfig['is_holiday'] ?? false;
        $absenceReason = $attendance->absence_reason;

        $hasClockIn = !empty($attendance->clock_in);
        $hasClockOut = !empty($attendance->clock_out);

        // 年休・欠勤の場合は打刻チェックをスキップ
        $isFullDayOff = in_array($absenceReason, ['paid_leave', 'absence']);

        // === 1. 打刻漏れ警告 ===

        // 平日で打刻なし（年休・欠勤以外）
        if (!$isHoliday && !$hasClockIn && !$hasClockOut && !$isFullDayOff) {
            $warnings[] = [
                'type' => 'missing_punch',
                'icon' => '⚠️',
                'message' => '打刻なし',
                'severity' => 'warning',
            ];
        }

        // 出勤打刻なし（退勤はある、年休・欠勤・午前半休以外）
        if (!$hasClockIn && $hasClockOut && !$isFullDayOff && $absenceReason !== 'am_half_leave') {
            $warnings[] = [
                'type' => 'missing_clock_in',
                'icon' => '⚠️',
                'message' => '出勤未打刻',
                'severity' => 'warning',
            ];
        }

        // 退勤打刻なし（出勤はある、年休・欠勤・午後半休以外）
        if ($hasClockIn && !$hasClockOut && !$isFullDayOff && $absenceReason !== 'pm_half_leave') {
            $warnings[] = [
                'type' => 'missing_clock_out',
                'icon' => '⚠️',
                'message' => '退勤未打刻',
                'severity' => 'warning',
            ];
        }

        // 以降は打刻がある場合のチェック
        if (!$hasClockIn && !$hasClockOut) {
            return $warnings;
        }

        // === 2. 遅刻警告 ===
        if ($hasClockIn && !$isHoliday && !$isFullDayOff) {
            $clockIn = $this->parseTimeOnly($attendance->clock_in);

            if ($absenceReason === 'am_half_leave') {
                // 午前半休: 13:00より後は遅刻
                $expectedStart = 13 * 60; // 13:00 = 780分
                if ($clockIn > $expectedStart) {
                    $lateMinutes = $clockIn - $expectedStart;
                    $warnings[] = [
                        'type' => 'late',
                        'icon' => '🕐',
                        'message' => '遅刻' . $lateMinutes . '分（午前半休）',
                        'severity' => 'info',
                    ];
                }
            } else {
                // 通常: 8:00より後は遅刻
                $expectedStart = 8 * 60; // 8:00 = 480分
                if ($clockIn > $expectedStart) {
                    $lateMinutes = $clockIn - $expectedStart;
                    $warnings[] = [
                        'type' => 'late',
                        'icon' => '🕐',
                        'message' => '遅刻' . $lateMinutes . '分',
                        'severity' => 'info',
                    ];
                }
            }
        }

        // === 3. 早退警告 ===
        if ($hasClockOut && !$isHoliday && !$isFullDayOff) {
            $clockOut = $this->parseTimeOnly($attendance->clock_out);

            if ($absenceReason === 'pm_half_leave') {
                // 午後半休: 12:00より前は早退
                $expectedEnd = 12 * 60; // 12:00 = 720分
                if ($clockOut < $expectedEnd) {
                    $earlyMinutes = $expectedEnd - $clockOut;
                    $warnings[] = [
                        'type' => 'early_leave',
                        'icon' => '🏃',
                        'message' => '早退' . $earlyMinutes . '分（午後半休）',
                        'severity' => 'info',
                    ];
                }
            } else {
                // 通常: 16:55より前は早退（残業なしの場合）
                $expectedEnd = 16 * 60 + 55; // 16:55 = 1015分
                $hasOvertime = $dailyCalculation['overtime_minutes'] > 0;
                if ($clockOut < $expectedEnd && !$hasOvertime) {
                    $earlyMinutes = $expectedEnd - $clockOut;
                    $warnings[] = [
                        'type' => 'early_leave',
                        'icon' => '🏃',
                        'message' => '早退' . $earlyMinutes . '分',
                        'severity' => 'info',
                    ];
                }
            }
        }

        // === 4. 所定時間不足警告 ===
        if (!$isHoliday && !$isFullDayOff && $hasClockIn && $hasClockOut) {
            $workMinutes = $dailyCalculation['regular_minutes'];
            $requiredMinutes = $this->config['regular_hours']['work_minutes']; // 465分

            // 半休の場合は半分
            if ($absenceReason === 'am_half_leave' || $absenceReason === 'pm_half_leave') {
                $requiredMinutes = (int)ceil($requiredMinutes / 2); // 233分
            }

            // 所定時間に足りていない場合（遅刻・早退とは別にチェック）
            if ($workMinutes < $requiredMinutes) {
                $shortMinutes = $requiredMinutes - $workMinutes;
                // 遅刻・早退警告と重複しないか確認
                $hasLateOrEarlyWarning = collect($warnings)->contains(function ($w) {
                    return in_array($w['type'], ['late', 'early_leave']);
                });

                if (!$hasLateOrEarlyWarning) {
                    $warnings[] = [
                        'type' => 'insufficient_hours',
                        'icon' => '📉',
                        'message' => '所定時間不足' . $shortMinutes . '分',
                        'severity' => 'warning',
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * 時刻文字列を分に変換（0:00からの分数）
     */
    protected function parseTimeOnly(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int)$parts[0];
        $minutes = (int)($parts[1] ?? 0);
        return $hours * 60 + $minutes;
    }
}
