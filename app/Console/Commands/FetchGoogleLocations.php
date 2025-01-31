<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleService;

class FetchGoogleLocations extends Command {
    // The name and signature of the console command.
    protected $signature = 'app:google:locations {--no-cache}';

    // The console command description.
    protected $description = 'Fetch locations from Google Business';

    // GoogleService instance
    protected $googleService;

    // Inject GoogleService into the command
    public function __construct(GoogleService $googleService) {
        parent::__construct();

        $this->googleService = $googleService;
    }

    // Execute the console command.
    public function handle() {
        $locations = $this->googleService->getLocations(!empty($this->option('no-cache')));

        if (!$locations) {
            $this->info('Google - No locations found or error fetching locations.');
            return;
        }

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
    }
}
