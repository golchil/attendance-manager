<?php

namespace App\Filament\Resources\PaidLeaveGrantResource\Pages;

use App\Filament\Resources\PaidLeaveGrantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPaidLeaveGrant extends EditRecord
{
    protected static string $resource = PaidLeaveGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
