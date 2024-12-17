<?php

namespace App\Console\Commands;

use App\Services\GoogleService;
use App\Services\SalesforceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class UpdateGoogleLocations extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:google:locations:update';

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
        $googleLocation = $this
            ->googleService
            ->getLocationByPlaceId('ChIJtQ1UKcj3X0YR6jKMg6ASNjY');

        $salesforceLocation = $this
            ->salesforceService
            ->getLocationByPlaceId('ChIJtQ1UKcj3X0YR6jKMg6ASNjY');

        if (!$googleLocation or !$salesforceLocation) {
            $this->info('Skipping ' . $googleLocation['storeCode'] . '. No google or Salesforce location found for this place ID');
            return;
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
            $this->info($googleLocation['storeCode'] . ' - Skipped. No changes found');
            return;
        }


        $this->info($googleLocation['storeCode'] . ' - Should update');

        try {
            $this->googleService->updateLocation($googleLocation['name'], ...$updateArgs);

            $this->info($googleLocation['storeCode'] . ' - Updated');
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
