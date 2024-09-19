<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

class SalesforceService {
	public function __construct() {
		$this->authenticate();
	}

	protected function authenticate() {
		try {
			// Attempt to authenticate and store the token
			Forrest::authenticate();
		} catch (\Exception $e) {
			// Handle authentication failures
			throw new \RuntimeException('Salesforce authentication failed: ' . $e->getMessage());
		}
	}

	public function getLocations() {
		$fields = [
			'Id',
			'Name',
			'Google_Place_ID__c',
			// Regular hours
			'BH_MondayStartTime__c',
			'BH_MondayEndTime__c',
			'BH_TuesdayStartTime__c',
			'BH_TuesdayEndTime__c',
			'BH_WednesdayStartTime__c',
			'BH_WednesdayEndTime__c',
			'BH_ThursdayStartTime__c',
			'BH_ThursdayEndTime__c',
			'BH_FridayStartTime__c',
			'BH_FridayEndTime__c',
			'BH_SaturdayStartTime__c',
			'BH_SaturdayEndTime__c',
			'BH_SundayStartTime__c',
			'BH_SundayEndTime__c',
			// Closed dates for moreHours
			'BH_SpecialDateClosed01__c',
			'BH_SpecialDateClosed02__c',
			'BH_SpecialDateClosed03__c',
			// Closed period for moreHours
			'BH_Closedbetween_StartDate__c',
			'BH_Closedbetween_EndDate__c',
		];

		/*
		// Regular lunch hours
		'BH_LunchTime_From__c',
		'BH_LunchTime_To__c',
		// Booleans if closed for lunch or not
		'BH_MondayLunchTime__c',
		'BH_TuesdayLunchTime__c',
		'BH_WednesdayLunchTime__c',
		'BH_ThursdayLunchTime__c',
		'BH_FridayLunchTime__c',
		'BH_SaturdayLunchTime__c',
		'BH_SundayLunchTime__c',
		*/

		Cache::forget("salesforce/locations");

		$locations = Cache::remember("salesforce/locations", 300, function () use ($fields) {
			$response = Forrest::query(sprintf('SELECT %s FROM Clinic__c WHERE Google_Place_ID__c != NULL', implode(', ', $fields)));
			return $response['records'];
		});

		return $locations;
	}

	public function getLocationByPlaceId(string $placeId) {
		return collect($this->getLocations())
			->firstWhere('Google_Place_ID__c', $placeId);
	}

	public function buildRegularHours(array $location) {
		$days = [
			'Monday' => ['start' => 'BH_MondayStartTime__c', 'end' => 'BH_MondayEndTime__c'],
			'Tuesday' => ['start' => 'BH_TuesdayStartTime__c', 'end' => 'BH_TuesdayEndTime__c'],
			'Wednesday' => ['start' => 'BH_WednesdayStartTime__c', 'end' => 'BH_WednesdayEndTime__c'],
			'Thursday' => ['start' => 'BH_ThursdayStartTime__c', 'end' => 'BH_ThursdayEndTime__c'],
			'Friday' => ['start' => 'BH_FridayStartTime__c', 'end' => 'BH_FridayEndTime__c'],
			'Saturday' => ['start' => 'BH_SaturdayStartTime__c', 'end' => 'BH_SaturdayEndTime__c'],
			'Sunday' => ['start' => 'BH_SundayStartTime__c', 'end' => 'BH_SundayEndTime__c'],
		];

		$regularHours = collect($days)
			->map(function ($times, $day) use ($location) {
				$startTime = Arr::get($location, $times['start']);
				$endTime   = Arr::get($location, $times['end']);

				if ($startTime && $endTime) {
					$startTime = Carbon::parse($startTime)->setTimezone('Europe/Stockholm')->format('H:i');
					$endTime = Carbon::parse($endTime)->setTimezone('Europe/Stockholm')->format('H:i');

					return [
						'day'       => strtoupper($day),
						'openTime'  => $startTime,
						'closeTime' => $endTime,
					];
				} else {
					// If either start or end time is null, the location is closed on that day
					return [
						'day'       => strtoupper($day),
						'openTime'  => null,
						'closeTime' => null,
					];
				}
			})
			->all();

		return $regularHours;
	}
}
