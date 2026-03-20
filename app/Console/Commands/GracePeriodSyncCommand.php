<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Models\SSL;
use App\Models\Hosting;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Counter;
use App\Models\Tool;
use App\Models\UserManagement;

class GracePeriodSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'grace-period:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync due dates and update status based on grace periods for all modules';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $models = [
            Subscription::class,
            SSL::class,
            Hosting::class,
            Domain::class,
            Email::class,
            Counter::class,
            Tool::class,
            UserManagement::class,
        ];

        foreach ($models as $modelClass) {
            $this->info("Syncing " . class_basename($modelClass) . "...");
            $count = 0;
            
            // We use each() to handle large datasets efficiently
            $modelClass::query()->each(function ($item) use (&$count) {
                $oldStatus = $item->status;
                $oldDueDate = $item->due_date;
                
                // The trait already has logic, but we can call the service directly if preferred
                \App\Services\GracePeriodService::syncModel($item);
                
                if ($oldStatus != $item->status || $oldDueDate != $item->due_date) {
                    $item->save();
                    $count++;
                }
            });

            $this->info("Updated {$count} records in " . class_basename($modelClass));
        }

        $this->info('Grace period sync completed successfully.');
    }
}
