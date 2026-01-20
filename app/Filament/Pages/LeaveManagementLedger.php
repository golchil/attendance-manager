<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\PaidLeaveService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class LeaveManagementLedger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = '勤怠管理';

    protected static ?string $title = '有給休暇管理簿';

    protected static ?string $navigationLabel = '有給休暇管理簿';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.leave-management-ledger';

    public ?int $selectedUserId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selectedUserId')
                    ->label('従業員を選択')
                    ->options(
                        User::where('is_active', true)
                            ->orderBy('employee_code')
                            ->get()
                            ->mapWithKeys(fn ($user) => [
                                $user->id => ($user->employee_code ? "[{$user->employee_code}] " : '') . $user->name
                            ])
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('従業員を選択してください')
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->selectedUserId = $state),
            ]);
    }

    public function getSelectedUser(): ?User
    {
        if (!$this->selectedUserId) {
            return null;
        }

        return User::with(['department', 'paidLeaveGrants'])->find($this->selectedUserId);
    }

    public function getYearlySummary(): array
    {
        $user = $this->getSelectedUser();
        if (!$user) {
            return [];
        }

        $service = app(PaidLeaveService::class);
        return $service->getYearlySummary($user, 5);
    }

    public function getLeaveDetails(): Collection
    {
        $user = $this->getSelectedUser();
        if (!$user) {
            return collect();
        }

        $service = app(PaidLeaveService::class);
        return $service->getLeaveUsageDetailsForYears($user, 5);
    }

    public function getCurrentBalance(): array
    {
        $user = $this->getSelectedUser();
        if (!$user) {
            return ['total_granted' => 0, 'total_used' => 0, 'total_remaining' => 0];
        }

        $service = app(PaidLeaveService::class);
        return $service->calculateBalance($user);
    }
}
