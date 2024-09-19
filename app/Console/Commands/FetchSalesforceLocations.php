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
        // $locations = $this->salesforceService->getLocations();

        $loc = $this->salesforceService->getLocationByPlaceId('ChIJOTKXxGCdX0YRasfZmn47WHE');
        dd($loc, $this->salesforceService->buildRegularHours($loc));
    }
}
