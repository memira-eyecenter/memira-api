<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleService;
use App\Services\SalesforceService;

class FetchGoogleLocations extends Command {
    // The name and signature of the console command.
    protected $signature = 'app:google:locations {--no-cache}';

    // The console command description.
    protected $description = 'Fetch locations from Google Business';

    // GoogleService instance
    protected $googleService;
    protected $salesforceService;

    // Inject GoogleService into the command
    public function __construct(GoogleService $googleService, SalesforceService $salesforceService) {
        parent::__construct();

        $this->googleService     = $googleService;
        $this->salesforceService = $salesforceService;
    }

    // Execute the console command.
    public function handle() {
        $locations = $this->googleService->getLocations(!empty($this->option('no-cache')));

        if ($locations) {
            $this->table(
                [
                    'Location ID',
                    'Store code',
                    'Status',
                    'Place ID',
                    'Regular hours',
                    'Special hours',
                ],
                collect($locations)
                    ->map(function ($location) {
                        $regularHours = $this->googleService->getRegularHours($location);
                        $specialHours = $this->googleService->getSpecialHours($location);

                        $location = collect($location)->dot();

                        if (!$location->has('metadata.placeId')) {
                            $location->put('metadata.placeId', null);
                        }

                        if (!$location->has('openInfo.status')) {
                            $location->put('openInfo.status', null);
                        }

                        return $location
                            ->only(
                                'name',
                                'storeCode',
                                'metadata.placeId',
                                'openInfo.status'
                            )
                            ->put('regularHours', $regularHours->toString(false))
                            ->put('specialHours', $specialHours->toString())
                            ->toArray();
                    })
                    ->all()
            );
        } else if (true === false and $locations) {
            // 6 cols
            $this->table([
                'ID',
                'Store code',
                'Status',
                'Google hours',
                'Salesforce hours',
                'Equal'
            ], collect($locations)
                ->map(function ($location) {
                    $placeId = $location['metadata']['placeId'] ?? null;

                    $salesforceLoc = $this->salesforceService->getLocationByPlaceId($placeId);

                    if ($salesforceLoc) {
                        $location['salesforceHours'] = $this->salesforceService->getRegularHours($salesforceLoc);
                    } else {
                        $location['salesforceHours'] = null;
                    }

                    $location['regularHours'] = $this->googleService->getRegularHours($location);

                    return $location;
                })
                ->whereNotNull('salesforceHours')
                ->whereNotIn('openInfo.status', ['CLOSED_PERMANENTLY'])
                ->map(function ($location) {
                    $loc = collect($location);
                    // 6 cols
                    return $loc
                        ->only('name', 'storeCode')
                        ->put('name', explode('/', $loc->get('name'))[1] ?? null)
                        ->put('status', $location['openInfo']['status'] ?? null)
                        ->put('regularHours', $loc->get('regularHours')->getHash())
                        ->put('salesforceHours', $loc->get('salesforceHours')->getHash())
                        ->put('equal', $loc->get('regularHours')->compare($loc->get('salesforceHours')))
                        ->toArray();
                })
                ->all());
        } else {
            $this->error('No locations found or error fetching locations.');
        }
    }
}
