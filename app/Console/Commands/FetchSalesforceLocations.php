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
        $locations = $this->salesforceService->getLocations();

        $this->table([
            'Location ID',
            'Name',
            'Place ID',
            'Regular hours',
            'Special hours',
        ], collect($locations)
            ->map(function ($location) {
                $regularHours = $this->salesforceService->getRegularHours($location);
                $specialHours = $this->salesforceService->getSpecialHours($location);

                $location = collect($location);

                if (!$location->has('Google_Place_ID__c')) {
                    $location->put('Google_Place_ID__c', null);
                }

                return $location
                    ->only(
                        'Id',
                        'Name',
                        'Google_Place_ID__c',
                    )
                    ->put('regularHours', $regularHours->toString(false))
                    ->put('specialHours', $specialHours->toString())
                    ->toArray();
            })
            ->all());
    }
}
