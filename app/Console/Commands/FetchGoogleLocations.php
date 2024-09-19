<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleService;

class FetchGoogleLocations extends Command {
    // The name and signature of the console command.
    protected $signature = 'app:google:locations';

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
        $locations = $this->googleService->getLocations();

        if ($locations) {
            $this->table(['ID', 'Store code', 'Hours'], collect($locations)
                ->map(function ($location) {
                    $loc = collect($location);
                    return $loc
                        ->only('name', 'storeCode', 'regularHours')
                        ->put('name', explode('/', $loc->get('name'))[1] ?? null)
                        ->put('regularHours', hash('sha256', json_encode($loc->get('regularHours'))))
                        ->toArray();
                })
                ->all());
        } else {
            $this->error('No locations found or error fetching locations.');
        }
    }
}
