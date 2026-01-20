<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = '勤怠管理';

    protected static ?string $modelLabel = '勤怠';

    protected static ?string $pluralModelLabel = '勤怠';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('基本情報')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('従業員')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\DatePicker::make('date')
                            ->label('日付')
                            ->required(),
                        Forms\Components\Select::make('day_type')
                            ->label('日種別')
                            ->options([
                                'weekday' => '平日',
                                'saturday' => '土曜',
                                'sunday' => '日曜',
                                'holiday' => '祝日',
                            ]),
                        Forms\Components\TextInput::make('shift_code')
                            ->label('シフトコード')
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('勤務時間')
                    ->schema([
                        Forms\Components\TimePicker::make('clock_in')
                            ->label('出勤時刻')
                            ->seconds(false),
                        Forms\Components\TimePicker::make('clock_out')
                            ->label('退勤時刻')
                            ->seconds(false),
                        Forms\Components\TimePicker::make('go_out_at')
                            ->label('外出時刻')
                            ->seconds(false),
                        Forms\Components\TimePicker::make('return_at')
                            ->label('戻り時刻')
                            ->seconds(false),
                        Forms\Components\TextInput::make('break_minutes')
                            ->label('休憩時間（分）')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('work_minutes')
                            ->label('勤務時間（分）')
                            ->numeric()
                            ->minValue(0),
                    ])->columns(3),

                Forms\Components\Section::make('ステータス')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('ステータス')
                            ->options([
                                'present' => '出勤',
                                'absent' => '欠勤',
                                'paid_leave' => '有給休暇',
                                'half_day_leave' => '半休',
                                'special_leave' => '特別休暇',
                                'late' => '遅刻',
                                'early_leave' => '早退',
                            ]),
                        Forms\Components\Textarea::make('note')
                            ->label('備考')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('日付')
                    ->date('Y/m/d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('従業員')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.department.name')
                    ->label('部署')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('day_type')
                    ->label('日種別')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'weekday' => '平日',
                        'saturday' => '土曜',
                        'sunday' => '日曜',
                        'holiday' => '祝日',
                        default => $state ?? '',
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('clock_in')
                    ->label('出勤')
                    ->time('H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('clock_out')
                    ->label('退勤')
                    ->time('H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('break_minutes')
                    ->label('休憩')
                    ->suffix('分')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('work_minutes')
                    ->label('勤務')
                    ->formatStateUsing(function (?int $state): string {
                        if ($state === null) return '';
                        $hours = floor($state / 60);
                        $minutes = $state % 60;
                        return sprintf('%d:%02d', $hours, $minutes);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('ステータス')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'present' => '出勤',
                        'absent' => '欠勤',
                        'paid_leave' => '有給休暇',
                        'half_day_leave' => '半休',
                        'special_leave' => '特別休暇',
                        'late' => '遅刻',
                        'early_leave' => '早退',
                        default => $state ?? '',
                    })
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'present' => 'success',
                        'absent' => 'danger',
                        'paid_leave', 'half_day_leave', 'special_leave' => 'info',
                        'late', 'early_leave' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->label('従業員'),
                Tables\Filters\SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'present' => '出勤',
                        'absent' => '欠勤',
                        'paid_leave' => '有給休暇',
                        'half_day_leave' => '半休',
                        'special_leave' => '特別休暇',
                        'late' => '遅刻',
                        'early_leave' => '早退',
                    ]),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('開始日'),
                        Forms\Components\DatePicker::make('until')
                            ->label('終了日'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('date', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->whereDate('date', '<=', $data['until']));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendances::route('/'),
            'create' => Pages\CreateAttendance::route('/create'),
            'edit' => Pages\EditAttendance::route('/{record}/edit'),
        ];
    }
}
