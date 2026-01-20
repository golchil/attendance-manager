<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaidLeaveGrantResource\Pages;
use App\Models\PaidLeaveGrant;
use App\Models\User;
use App\Services\PaidLeaveService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class PaidLeaveGrantResource extends Resource
{
    protected static ?string $model = PaidLeaveGrant::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = '勤怠管理';

    protected static ?string $modelLabel = '有給付与';

    protected static ?string $pluralModelLabel = '有給付与履歴';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('付与情報')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('従業員')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $user = User::find($state);
                                    if ($user && $user->effective_leave_grant_date) {
                                        $grantDate = $user->effective_leave_grant_date;
                                        $set('grant_date', $grantDate->format('Y-m-d'));
                                        $set('fiscal_year_start', $grantDate->format('Y-m-d'));
                                        $set('expires_at', $grantDate->copy()->addYears(2)->format('Y-m-d'));

                                        // Calculate days to grant
                                        $service = app(PaidLeaveService::class);
                                        $useOldRule = $service->isUsingOldRule($user);
                                        $days = $service->calculateGrantDays($user, $grantDate, $useOldRule);
                                        $set('days_granted', $days);
                                    }
                                }
                            }),
                        Forms\Components\DatePicker::make('grant_date')
                            ->label('付与日')
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                if ($state) {
                                    $grantDate = Carbon::parse($state);
                                    $set('fiscal_year_start', $state);
                                    $set('expires_at', $grantDate->copy()->addYears(2)->format('Y-m-d'));

                                    // Recalculate days if user is selected
                                    $userId = $get('user_id');
                                    if ($userId) {
                                        $user = User::find($userId);
                                        if ($user) {
                                            $service = app(PaidLeaveService::class);
                                            $useOldRule = $service->isUsingOldRule($user);
                                            $days = $service->calculateGrantDays($user, $grantDate, $useOldRule);
                                            $set('days_granted', $days);
                                        }
                                    }
                                }
                            }),
                        Forms\Components\TextInput::make('days_granted')
                            ->label('付与日数')
                            ->numeric()
                            ->step(0.5)
                            ->minValue(0)
                            ->maxValue(20)
                            ->required()
                            ->suffix('日'),
                        Forms\Components\DatePicker::make('fiscal_year_start')
                            ->label('年度開始日')
                            ->required(),
                        Forms\Components\DatePicker::make('expires_at')
                            ->label('有効期限')
                            ->required()
                            ->helperText('付与日から2年後が標準'),
                        Forms\Components\Textarea::make('note')
                            ->label('備考')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('従業員')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.department.name')
                    ->label('部署')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('grant_date')
                    ->label('付与日')
                    ->date('Y/m/d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('days_granted')
                    ->label('付与日数')
                    ->suffix('日')
                    ->sortable(),
                Tables\Columns\TextColumn::make('days_remaining')
                    ->label('残日数')
                    ->getStateUsing(function ($record) {
                        $service = app(PaidLeaveService::class);
                        return $service->getGrantBalance($record);
                    })
                    ->suffix('日')
                    ->color(fn ($state) => $state <= 3 ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('有効期限')
                    ->date('Y/m/d')
                    ->sortable()
                    ->color(function ($record) {
                        if ($record->isExpired()) {
                            return 'danger';
                        }
                        if ($record->expires_at->lte(Carbon::today()->addMonths(3))) {
                            return 'warning';
                        }
                        return null;
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('有効')
                    ->getStateUsing(fn ($record) => $record->isActive())
                    ->boolean(),
            ])
            ->defaultSort('grant_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->label('従業員'),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('有効な付与のみ')
                    ->queries(
                        true: fn ($query) => $query->where('expires_at', '>=', Carbon::today()),
                        false: fn ($query) => $query->where('expires_at', '<', Carbon::today()),
                    ),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaidLeaveGrants::route('/'),
            'create' => Pages\CreatePaidLeaveGrant::route('/create'),
            'edit' => Pages\EditPaidLeaveGrant::route('/{record}/edit'),
        ];
    }
}
