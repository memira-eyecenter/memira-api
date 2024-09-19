<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SalesforceService;

class FetchSalesforceLocations extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:salesforce:locations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch locations from Salesforce';

    protected $salesforceService;

    public function __construct(SalesforceService $salesforceService) {
        parent::__construct();
        $this->salesforceService = $salesforceService;
    }

    /**
     * Execute the console command.
     */
    public function handle() {
        dd($this->salesforceService->getLocations());

        // $location     = $this->salesforceService->getLocationByPlaceId('ChIJOTKXxGCdX0YRasfZmn47WHE');
        // $regularHours = $this->salesforceService->transformIntoGoogleRegularHours($location);
        // dd(compact('location', 'regularHours'));
    }
}
