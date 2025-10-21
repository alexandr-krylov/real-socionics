<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\InfobipVerifyService;

class SetupInfobipVerify extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:setup-infobip-verify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(InfobipVerifyService $service)
    {
        $appName = $this->argument('name');

        $this->info("🔧 Creating Infobip Verify application...");
        $appId = $service->createApplication($appName);
        $this->info("✅ Application created: {$appId}");

        $this->info("📄 Creating verification template...");
        $templateId = $service->createTemplate('LoginVerification', 'Your verification code is {{pin}}.');
        $this->info("✅ Template created: {$templateId}");

        $this->info("🎉 Setup complete! IDs saved in storage/app/infobip.json");
    }
}
