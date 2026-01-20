# 勤怠管理システム (Attendance Manager)

## プロジェクト概要

従業員の勤怠管理と有給休暇管理を行うWebアプリケーション。
タイムカードCSVからのデータインポート、勤務時間計算、有給残日数管理、有給休暇管理簿の出力機能を提供。

## 技術スタック

- **PHP**: 8.2+
- **Laravel**: 12.0
- **Filament**: 3.0 (管理画面フレームワーク)
- **Laravel Sail**: Docker開発環境
- **MariaDB**: データベース
- **Vite**: フロントエンドビルド

## 開発環境

```bash
# 起動
./vendor/bin/sail up -d

# マイグレーション
./vendor/bin/sail artisan migrate

# キャッシュクリア
./vendor/bin/sail artisan optimize:clear

# Tinkerでデバッグ
./vendor/bin/sail artisan tinker
```

**アクセスURL**: http://localhost:8080/admin

## ディレクトリ構造

```
app/
├── Filament/
│   ├── Pages/           # カスタムページ
│   │   ├── LeaveBalanceSummary.php      # 有給残日数一覧
│   │   └── LeaveManagementLedger.php    # 有給休暇管理簿
│   └── Resources/       # CRUD リソース
│       ├── AttendanceResource.php       # 勤怠管理
│       ├── DepartmentResource.php       # 部署管理
│       ├── PaidLeaveGrantResource.php   # 有給付与管理
│       └── UserResource.php             # 従業員管理
├── Http/Controllers/
│   └── LeaveManagementLedgerController.php  # 印刷用コントローラー
├── Models/
│   ├── Attendance.php       # 勤怠
│   ├── Department.php       # 部署
│   ├── PaidLeaveGrant.php   # 有給付与
│   ├── PaidLeaveUsage.php   # 有給消化
│   └── User.php             # 従業員
└── Services/
    ├── AttendanceCalculator.php         # 勤務時間計算
    ├── AttendanceCsvImporter.php        # 勤怠CSVインポート
    ├── EmployeeCsvImporter.php          # 従業員CSVインポート
    ├── InitialLeaveBalanceCsvImporter.php # 初期残日数インポート
    ├── PaidLeaveService.php             # 有給計算サービス
    └── PaidLeaveUsageCsvImporter.php    # 有給消化履歴インポート
```

## データベース構造

### users（従業員）
| カラム | 型 | 説明 |
|--------|------|------|
| id | bigint | 主キー |
| employee_code | varchar | 社員番号 |
| name | varchar | 氏名 |
| normalized_name | varchar | 照合用氏名（スペース除去、カナ正規化） |
| card_name | varchar | タイムカード名（別名がある場合） |
| normalized_card_name | varchar | 照合用タイムカード名 |
| card_number | varchar | カード番号 |
| email | varchar | メールアドレス |
| department_id | bigint | 部署ID |
| position | varchar | 役職 |
| employment_type | varchar | 雇用形態 (full_time/part_time/contract/temporary) |
| joined_at | date | 入社日 |
| leave_grant_date | date | 有給付与基準日 |
| leave_grant_month | int | 有給付与月 (1-12) |
| initial_leave_balance | decimal | 初期残日数 |
| initial_leave_date | date | 初期基準日 |
| initial_leave_imported | boolean | 初期データインポート済フラグ |
| is_active | boolean | 有効フラグ |

### attendances（勤怠）
| カラム | 型 | 説明 |
|--------|------|------|
| id | bigint | 主キー |
| user_id | bigint | 従業員ID |
| date | date | 日付 |
| day_type | varchar | 日種別 (00:平日, 01:法定休日, 02:所定休日) |
| clock_in | time | 出勤時刻 |
| clock_out | time | 退勤時刻 |
| go_out_at | time | 外出時刻 |
| return_at | time | 戻り時刻 |
| break_minutes | int | 休憩時間（分） |
| work_minutes | int | 勤務時間（分） |
| status | varchar | ステータス |
| absence_reason | varchar | 不在理由 (paid_leave/am_half_leave/pm_half_leave/absence) |
| shift_code | varchar | シフト番号 |
| note | text | 備考 |

### paid_leave_grants（有給付与）
| カラム | 型 | 説明 |
|--------|------|------|
| id | bigint | 主キー |
| user_id | bigint | 従業員ID |
| grant_date | date | 付与日 |
| days_granted | decimal | 付与日数 |
| fiscal_year_start | date | 年度開始日 |
| expires_at | date | 有効期限 |
| note | text | 備考 |

### paid_leave_usages（有給消化）
| カラム | 型 | 説明 |
|--------|------|------|
| id | bigint | 主キー |
| user_id | bigint | 従業員ID |
| date | date | 取得日 |
| leave_type | varchar | 種別 (paid_leave/am_half_leave/pm_half_leave) |
| days | decimal | 日数 (1.0 or 0.5) |

### departments（部署）
| カラム | 型 | 説明 |
|--------|------|------|
| id | bigint | 主キー |
| code | varchar | 部署コード |
| name | varchar | 部署名 |

## 重要な設定

### 勤怠設定 (config/attendance.php)

```php
'closing_day' => 20,           // 締め日（20日）

'regular_hours' => [
    'start' => '08:00',        // 定時開始
    'end' => '16:55',          // 定時終了
    'work_minutes' => 465,     // 所定労働時間（7時間45分）
],

'breaks' => [                  // 休憩時間（合計75分）
    ['start' => '10:00', 'end' => '10:05'],  // 午前休憩 5分
    ['start' => '12:00', 'end' => '13:00'],  // 昼休憩 60分
    ['start' => '15:00', 'end' => '15:05'],  // 午後休憩 5分
    ['start' => '16:55', 'end' => '17:00'],  // 夕方休憩 5分
],

'day_types' => [
    '00' => '平日',
    '01' => '法定休日',
    '02' => '所定休日',
],

'absence_reasons' => [
    'paid_leave' => '年休',
    'am_half_leave' => '午前半休',
    'pm_half_leave' => '午後半休',
    'absence' => '欠勤',
],
```

### 有給付与日数（労働基準法準拠）

| 勤続年数 | 付与日数 |
|----------|----------|
| 0.5年 | 10日 |
| 1.5年 | 11日 |
| 2.5年 | 12日 |
| 3.5年 | 14日 |
| 4.5年 | 16日 |
| 5.5年 | 18日 |
| 6.5年以上 | 20日 |

- 繰越上限: 20日
- 最大保有: 40日（当年20日 + 繰越20日）

## CSVインポート機能

### 1. 勤怠CSVインポート

**ファイル**: 勤怠管理 > CSVインポート

**フォーマット** (Shift-JIS):
```csv
カード番号,従業員番号,従業員氏名,所属番号,年/月/日,シフト番号,平日/休日区分,不在理由,出勤打刻,出勤マーク,外出打刻,外出マーク,戻打刻,戻マーク,退勤打刻,退勤マーク,例外１,例外マーク,例外２,例外２マーク,コメント
```

**従業員照合優先順位**:
1. employee_code（社員番号）
2. normalized_name / normalized_card_name（正規化された氏名）
3. card_number（カード番号）

### 2. 有給消化履歴インポート

**ファイル**: 有給残日数一覧 > 有給消化履歴インポート

**フォーマット** (Shift-JIS):
```csv
氏名,日付,有給,午前/午後
上武　政一,2024/4/30,1,
田中　太郎,2024/5/1,0.5,午前
鈴木　花子,2024/5/2,0.5,午後
```

### 3. 初期残日数インポート

**ファイル**: 有給残日数一覧 > 初期残日数インポート

**フォーマット** (Shift-JIS):
```csv
社員番号,氏名,賃金計算期間開始日,賃金計算期間終了日,有休残
000004,上武　政一,12月21日,01月20日,15.0
```

※ 賃金計算期間終了日の月と従業員の`leave_grant_month`が一致する場合のみ更新

## 主要機能

### 勤怠管理
- 勤怠一覧・編集
- タイムカードCSVからのインポート
- 勤務時間自動計算

### 従業員管理
- 従業員CRUD
- タイムカード名設定（氏名と異なる場合の照合用）
- 有給付与基準日・付与月設定

### 有給休暇管理
- **有給残日数一覧**: 全従業員の残日数を一覧表示
- **有給休暇管理簿**: 従業員別の詳細（印刷/PDF出力対応）
- **有給付与管理**: 付与レコードのCRUD
- 消化履歴インポート
- 初期残日数インポート

### 印刷機能
有給休暇管理簿は専用の印刷ページ (`/leave-ledger/print/{user}`) で出力。
Filamentのレイアウトを使わないシンプルなHTMLで印刷に最適化。

## 名前照合ロジック

`User::findByNameOrCard()` メソッドで以下の優先順位で照合:

1. `normalized_name`: 氏名を正規化（スペース除去、半角カナ→全角カナ）
2. `normalized_card_name`: タイムカード名を正規化
3. `card_number`: カード番号（完全一致）

正規化処理 (`User::normalizeName()`):
- `mb_convert_kana($name, 'KVas')`: 半角カナ→全角カナ、半角英数→全角
- スペース除去（全角・半角両方）

## 注意事項

- 有給消化は `paid_leave_usages` テーブルと `attendances` テーブルの両方から集計
- 重複カウント防止のため日付キーで管理
- CSVインポート時、既存ユーザーが見つからない場合は新規作成される
- 印刷機能はFilamentのレイアウトと互換性がないため別ページで実装
