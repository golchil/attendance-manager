<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\PaidLeaveService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'マスタ管理';

    protected static ?string $modelLabel = '従業員';

    protected static ?string $pluralModelLabel = '従業員';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('基本情報')
                    ->schema([
                        Forms\Components\TextInput::make('employee_code')
                            ->label('社員番号')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('name')
                            ->label('氏名')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Placeholder::make('normalized_name_display')
                            ->label('照合用氏名')
                            ->content(fn ($record) => $record?->normalized_name ?? '-')
                            ->visible(fn ($record) => $record !== null)
                            ->helperText('自動生成（スペース除去、カナ正規化済み）'),
                        Forms\Components\TextInput::make('card_name')
                            ->label('タイムカード名')
                            ->maxLength(255)
                            ->helperText('タイムカードCSVの名前が異なる場合に設定してください'),
                        Forms\Components\Placeholder::make('normalized_card_name_display')
                            ->label('照合用タイムカード名')
                            ->content(fn ($record) => $record?->normalized_card_name ?? '-')
                            ->visible(fn ($record) => $record?->card_name !== null)
                            ->helperText('自動生成（スペース除去、カナ正規化済み）'),
                        Forms\Components\TextInput::make('email')
                            ->label('メールアドレス')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label('パスワード')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('勤務情報')
                    ->schema([
                        Forms\Components\TextInput::make('card_number')
                            ->label('カード番号')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\Select::make('department_id')
                            ->label('部署')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('position')
                            ->label('役職')
                            ->maxLength(255),
                        Forms\Components\Select::make('employment_type')
                            ->label('雇用形態')
                            ->options([
                                'full_time' => '正社員',
                                'part_time' => 'パート',
                                'contract' => '契約社員',
                                'temporary' => '派遣社員',
                            ]),
                        Forms\Components\DatePicker::make('joined_at')
                            ->label('入社日')
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $calculated = Carbon::parse($state)->addMonths(6)->startOfMonth();
                                    $set('leave_grant_date', $calculated->format('Y-m-d'));
                                }
                            }),
                        Forms\Components\Toggle::make('is_active')
                            ->label('有効')
                            ->default(true),
                        Forms\Components\DatePicker::make('leave_grant_date')
                            ->label('有給付与基準日')
                            ->helperText(function ($get) {
                                $joinedAt = $get('joined_at');
                                if ($joinedAt) {
                                    $calculated = Carbon::parse($joinedAt)->addMonths(6)->startOfMonth()->format('Y/m/d');
                                    return "自動計算: {$calculated}（入社日から6ヶ月後の月初）";
                                }
                                return '入社日を設定すると自動計算されます';
                            }),
                        Forms\Components\Select::make('leave_grant_month')
                            ->label('有給付与月')
                            ->options([
                                1 => '1月', 2 => '2月', 3 => '3月', 4 => '4月',
                                5 => '5月', 6 => '6月', 7 => '7月', 8 => '8月',
                                9 => '9月', 10 => '10月', 11 => '11月', 12 => '12月',
                            ])
                            ->helperText('毎年有給が付与される月'),
                        Forms\Components\Placeholder::make('leave_balance_display')
                            ->label('有給残日数')
                            ->content(function ($record) {
                                if (!$record) {
                                    return '-';
                                }
                                $service = app(PaidLeaveService::class);
                                $balance = $service->calculateBalance($record);
                                return $balance['total_remaining'] . '日';
                            })
                            ->visible(fn ($record) => $record !== null),
                    ])->columns(2),

                Forms\Components\Section::make('初期残日数設定')
                    ->description('過去の有給残日数を設定して、そこから計算を開始します')
                    ->schema([
                        Forms\Components\DatePicker::make('initial_leave_date')
                            ->label('初期基準日')
                            ->helperText('この日時点の残日数を設定'),
                        Forms\Components\TextInput::make('initial_leave_balance')
                            ->label('初期残日数')
                            ->numeric()
                            ->step(0.5)
                            ->minValue(0)
                            ->maxValue(40)
                            ->suffix('日')
                            ->helperText('初期基準日時点での有給残日数（0〜40日）'),
                    ])->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee_code')
                    ->label('社員番号')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('氏名')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('department.name')
                    ->label('部署')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('position')
                    ->label('役職')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('employment_type')
                    ->label('雇用形態')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'full_time' => '正社員',
                        'part_time' => 'パート',
                        'contract' => '契約社員',
                        'temporary' => '派遣社員',
                        default => $state ?? '',
                    })
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('joined_at')
                    ->label('入社日')
                    ->date('Y/m/d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('leave_grant_date')
                    ->label('有給付与基準日')
                    ->date('Y/m/d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('leave_grant_month')
                    ->label('付与月')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state}月" : '-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('leave_balance')
                    ->label('有給残')
                    ->getStateUsing(function ($record) {
                        $service = app(PaidLeaveService::class);
                        $balance = $service->calculateBalance($record);
                        return $balance['total_remaining'];
                    })
                    ->suffix('日')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('department')
                    ->relationship('department', 'name')
                    ->label('部署'),
                Tables\Filters\SelectFilter::make('employment_type')
                    ->label('雇用形態')
                    ->options([
                        'full_time' => '正社員',
                        'part_time' => 'パート',
                        'contract' => '契約社員',
                        'temporary' => '派遣社員',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('有効'),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
