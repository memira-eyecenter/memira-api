<?php

namespace App\Console\Commands;

use App\Services\GoogleService;
use App\Services\SalesforceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LocationsReport extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:locations:report {--no-cache} {--format=table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lists both Google and Salesforce locations, and shows if any differences between them';

    // GoogleService instance
    protected $googleService;

    // SalesforceService instance
    protected $salesforceService;

    public function __construct(GoogleService $googleService, SalesforceService $salesforceService) {
        parent::__construct();

        $this->googleService     = $googleService;
        $this->salesforceService = $salesforceService;
    }

    /**
     * Execute the console command.
     */
    public function handle() {
        $googleLocations     = $this->googleService->getLocations(!empty($this->option('no-cache')));
        $salesforceLocations = $this->salesforceService->getLocations(!empty($this->option('no-cache')));

        $rows = collect($googleLocations)
            ->map(function ($location) {
                $googleRegularHours = $this->googleService->getRegularHours($location);
                $googleSpecialHours = $this->googleService->getSpecialHours($location);

                $googleLocation = collect($location)->dot();

                if (!$googleLocation->has('openInfo.status')) {
                    $googleLocation->put('openInfo.status', null);
                }

                $hasPlaceId = $googleLocation->has('metadata.placeId');

                $row = $googleLocation
                    ->only('storeCode')
                    ->put('salesforceName', 'NO_SALESFORCE_CLINIC')
                    ->put('status', $googleLocation->get('openInfo.status'))
                    ->put('placeId', $hasPlaceId ? 'Yes' : 'No')
                    ->put('regularHours', $googleRegularHours->toString(false))
                    ->put('specialHours', $googleSpecialHours->toString());

                if ($hasPlaceId and $salesforceLocation = $this->salesforceService->getLocationByPlaceId($googleLocation->get('metadata.placeId'))) {
                    $salesforceRegularHours = $this->salesforceService->getRegularHours($salesforceLocation);
                    $salesforceSpecialHours = $this->salesforceService->getSpecialHours($salesforceLocation);

                    $row->put('salesforceName', $salesforceLocation['Name']);
                    $row->put('salesforceRegularHours', $salesforceRegularHours->toString(false));
                    $row->put('salesforceSpecialHours', $salesforceSpecialHours->toString());
                    $row->put('regularDiff', $salesforceRegularHours->compare($googleRegularHours) ? 'EQUAL' : 'DIFFERENT');
                    $row->put('specialDiff', $salesforceSpecialHours->compare($googleSpecialHours) ? 'EQUAL' : 'DIFFERENT');
                } else {
                    $row->put('salesforceRegularHours', 'NO_SALESFORCE_CLINIC');
                    $row->put('salesforceSpecialHours', 'NO_SALESFORCE_CLINIC');
                    $row->put('regularDiff', 'NO_SALESFORCE_CLINIC');
                    $row->put('specialDiff', 'NO_SALESFORCE_CLINIC');
                }

                return $row->toArray();
            })
            ->sortBy('salesforceName')
            ->all();

        $headers = [
            'Store code',
            'Salesforce name',
            'Google Status',
            'Google Place ID?',
            'Google regular hours',
            'Google special hours',
            'Salesforce regular hours',
            'Salesforce special hours',
            'Regular hours compare',
            'Special hours compare',
        ];

        if ($this->option('format') === 'csv') {
            if (!Storage::directoryExists('reports')) {
                Storage::makeDirectory('reports');
            }

            $csvPath = Storage::path('reports/locations_report_' . now()->format('Ymd_Hi') . '.csv');

            $csvFile = fopen($csvPath, 'w');

            fputcsv($csvFile, $headers);

            collect($rows)->each(function ($row) use ($csvFile) {
                fputcsv($csvFile, $row);
            });

            fclose($csvFile);

            $this->info('CSV report saved to: ' . $csvPath);
            return;
        }

        $this->table(
            $headers,
            $rows,
        );
    }
}
