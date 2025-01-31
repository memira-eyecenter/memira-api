<?php

namespace App\Console\Commands;

use App\Services\GoogleService;
use App\Services\SalesforceService;
use Illuminate\Console\Command;

class UpdateGoogleLocations extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:locations:google:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Google Business locations';

    protected $googleService;
    protected $salesforceService;

    public function __construct(GoogleService $googleService, SalesforceService $salesforceService) {
        parent::__construct();
        $this->googleService = $googleService;
        $this->salesforceService = $salesforceService;
    }

    /**
     * Execute the console command.
     */
    public function handle() {
        $googleLocations = $this->googleService->getLocations();

        if (!$googleLocations) {
            $this->info('Skipping - No Google locations found');
            return;
        }

        foreach ($googleLocations as $googleLocation) {
            $googlePlaceId = $googleLocation['metadata']['placeId'] ?? null;
            $storeCode     = $googleLocation['storeCode'] ?? '';
            $locality      = $googleLocation['storefrontAddress']['locality'] ?? '';

            $logName = $storeCode;

            if ($locality) {
                $logName = trim("{$logName} {$locality}");
            }

            if (!$logName) {
                $logName = $googleLocation['name'] ?? 'Unknown';
            }

            if (!$googlePlaceId) {
                $this->info($logName . ' - Skipped - Google location has no Google Place ID');
                continue;
            }

            $salesforceLocation = $this
                ->salesforceService
                ->getLocationByPlaceId($googlePlaceId);

            if (!$salesforceLocation) {
                $this->info($logName . ' - Skipped - Salesforce location not found');
                continue;
            }

            // Save regular hours for comparison
            $salesforceRegularHours = $this->salesforceService->getRegularHours($salesforceLocation);
            $salesforceSpecialHours = $this->salesforceService->getSpecialHours($salesforceLocation);

            // Save special hours for comparison
            $googleRegularHours = $this->googleService->getRegularHours($googleLocation);
            $googleSpecialHours = $this->googleService->getSpecialHours($googleLocation);

            // Default to not update
            $updateArgs = [null, null];

            if ($googleRegularHours->compare($salesforceRegularHours) === false) {
                $updateArgs[0] = $salesforceRegularHours;
            }

            if ($googleSpecialHours->compare($salesforceSpecialHours) === false) {
                $updateArgs[1] = $salesforceSpecialHours;
            }

            if (empty($updateArgs[0]) and empty($updateArgs[1])) {
                $this->info($logName . ' - Skipped - No changes found');
                continue;
            }

            if ($updateArgs[0]) {
                $this->info($logName . ' - Update regular - ' . ($googleRegularHours->toString() ?: 'EMPTY') . ' -> ' . ($salesforceRegularHours->toString() ?: 'EMPTY'));
            }

            if ($updateArgs[1]) {
                $this->info($logName . ' - Update special - ' . ($googleSpecialHours->toString() ?: 'EMPTY') . ' -> ' . ($salesforceSpecialHours->toString() ?: 'EMPTY'));
            }

            try {
                $this->googleService->updateLocation($googleLocation['name'], ...$updateArgs);
                $this->info($logName . ' - Updated');
            } catch (\Exception $e) {
                $this->error($logName . ' - Update error - ' . $e->getMessage());
            }
        }
    }
}
