<?php

namespace App\Services;

use App\Utilities\RegularHours;
use App\Utilities\SpecialHours;
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

	public function getLocations(bool $forceUpdate = false): array {
		$fields = [
			'Id',
			'Name',
			'Country__c',
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
			// General lunch hours
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
			// Closed dates for moreHours
			'BH_SpecialDateClosed01__c',
			'BH_SpecialDateClosed02__c',
			'BH_SpecialDateClosed03__c',
			// Closed period for moreHours
			'BH_Closedbetween_StartDate__c',
			'BH_Closedbetween_EndDate__c',
			// Special open dates
			'BH_SpecialDateTimeOpen01__c',
			'BH_SpecialTimeClosed01__c',
			'BH_SpecialDateTimeOpen02__c',
			'BH_SpecialTimeClosed02__c',
		];

		if ($forceUpdate) {
			Cache::forget("salesforce/locations");
		}

		$locations = Cache::remember("salesforce/locations", 300, function () use ($fields) {
			$response = Forrest::query(sprintf('SELECT %s FROM Clinic__c WHERE Google_Place_ID__c != NULL', implode(', ', $fields)));
			return $response['records'];
		});

		return $locations;
	}

	public function getLocationByPlaceId(string $placeId): array|null {
		if (!$placeId) {
			return null;
		}
		return collect($this->getLocations())
			->firstWhere('Google_Place_ID__c', $placeId);
	}

	public function getRegularHours(array $location) {
		if (!$location or !is_array($location)) {
			return null;
		}

		$days = [
			'MONDAY' => [
				'start' => 'BH_MondayStartTime__c',
				'end'   => 'BH_MondayEndTime__c',
				'lunch' => 'BH_MondayLunchTime__c'
			],
			'TUESDAY' => [
				'start' => 'BH_TuesdayStartTime__c',
				'end'   => 'BH_TuesdayEndTime__c',
				'lunch' => 'BH_TuesdayLunchTime__c'
			],
			'WEDNESDAY' => [
				'start' => 'BH_WednesdayStartTime__c',
				'end'   => 'BH_WednesdayEndTime__c',
				'lunch' => 'BH_WednesdayLunchTime__c'
			],
			'THURSDAY' => [
				'start' => 'BH_ThursdayStartTime__c',
				'end'   => 'BH_ThursdayEndTime__c',
				'lunch' => 'BH_ThursdayLunchTime__c'
			],
			'FRIDAY' => [
				'start' => 'BH_FridayStartTime__c',
				'end'   => 'BH_FridayEndTime__c',
				'lunch' => 'BH_FridayLunchTime__c'
			],
			'SATURDAY' => [
				'start' => 'BH_SaturdayStartTime__c',
				'end'   => 'BH_SaturdayEndTime__c',
				'lunch' => 'BH_SaturdayLunchTime__c'
			],
			'SUNDAY' => [
				'start' => 'BH_SundayStartTime__c',
				'end'   => 'BH_SundayEndTime__c',
				'lunch' => 'BH_SundayLunchTime__c'
			],
		];

		$regularHours = new RegularHours();

		collect($days)
			->each(function ($times, $day) use ($location, $regularHours) {
				$startTime  = Arr::get($location, $times['start']);
				$endTime    = Arr::get($location, $times['end']);
				$lunchTime  = Arr::get($location, $times['lunch']);
				$lunchStart = Arr::get($location, 'BH_LunchTime_From__c');
				$lunchEnd   = Arr::get($location, 'BH_LunchTime_To__c');

				if (empty($startTime) || empty($endTime)) {
					return;
				}

				// Note: Output from Salesforce is in input format (local time), so is falsely presented in UTC
				$openTime  = Carbon::parse((string) substr($startTime, 0, 5));
				$closeTime = Carbon::parse((string) substr($endTime, 0, 5));

				if ($lunchTime and $lunchStart and $lunchEnd) {
					// Note: Output from Salesforce is in input format (local time), so is falsely presented in UTC
					$lunchStartTime = Carbon::parse(substr((string) $lunchStart, 0, 5));
					$lunchEndTime   = Carbon::parse(substr((string) $lunchEnd, 0, 5));

					$regularHours->addPeriod($day, $openTime, $day, $lunchStartTime);
					$regularHours->addPeriod($day, $lunchEndTime, $day, $closeTime);

					return;
				}

				$regularHours->addPeriod($day, $openTime, $day, $closeTime);
			});

		return $regularHours;
	}

	public function getSpecialHours(array $location) {
		if (!$location or !is_array($location)) {
			return null;
		}

		$specialHours = new SpecialHours();

		if (!empty($location['BH_SpecialDateClosed01__c'])) {
			$specialHours->addClosedDay($location['BH_SpecialDateClosed01__c']);
		}

		if (!empty($location['BH_SpecialDateClosed02__c'])) {
			$specialHours->addClosedDay($location['BH_SpecialDateClosed02__c']);
		}

		if (!empty($location['BH_SpecialDateClosed03__c'])) {
			$specialHours->addClosedDay($location['BH_SpecialDateClosed03__c']);
		}

		if (!empty($location['BH_Closedbetween_StartDate__c']) and !empty($location['BH_Closedbetween_EndDate__c'])) {
			$specialHours->addPeriod(false, $location['BH_Closedbetween_StartDate__c'], $location['BH_Closedbetween_EndDate__c']);
		}

		foreach (['01', '02'] as $num) {
			if (!empty($location["BH_SpecialDateTimeOpen{$num}__c"]) and !empty($location["BH_SpecialTimeClosed{$num}__c"])) {
				$startDate = Carbon::parse($location["BH_SpecialDateTimeOpen{$num}__c"]);

				// Note: Output from Salesforce is in UTC, so we need to adjust the timezone accordingly.
				switch ($location['Country__c']) {
					case 'Sweden':
						$startDate->setTimezone('Europe/Stockholm');
						break;
					case 'Norway':
						$startDate->setTimezone('Europe/Oslo');
						break;
					case 'Denmark':
						$startDate->setTimezone('Europe/Copenhagen');
						break;
					case 'Netherlands':
						$startDate->setTimezone('Europe/Amsterdam');
						break;
				}

				$openTime = $startDate->format('H:i');

				// Normalise datetime to date only
				$startDate->setTime(0, 0);

				// Note: Output from Salesforce is in input format (local time), so is falsely presented in UTC
				$closeTime = substr($location["BH_SpecialTimeClosed{$num}__c"], 0, 5);

				$specialHours->addPeriod(true, $startDate, $startDate, $openTime, $closeTime);
			}
		}

		return $specialHours;
	}
}
